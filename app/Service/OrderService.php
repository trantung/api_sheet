<?php

namespace App\Service;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use App\Service\CartService;

class OrderService
{
    public function setupTenantConnection($dbName)
    {
        $connectionName = 'tenant';

        // Set the configuration for the tenant connection
        Config::set("database.connections.$connectionName", [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', 3306),
            'database'  => $dbName,
            'username'  => env('DB_USERNAME'),
            'password'  => env('DB_PASSWORD'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);

        // Clear the previous connection (if any) and reconnect
        DB::purge($connectionName);
        DB::reconnect($connectionName);

        return $connectionName;
    }

    public function getDomainFromRequest()
    {
        $origin = request()->header('Origin');
        $domain = null;
        if ($origin) {
            $parsed = parse_url($origin);
            $domain = $parsed['host'] ?? null;
        }
        if ($domain == 'localhost') {
            $domain = 'domain2f.microgem.io.vn';
        }
        
        // Normalize domain: remove www, lowercase
        if ($domain) {
            $domain = strtolower($domain);
            if (strpos($domain, 'www.') === 0) {
                $domain = substr($domain, 4);
            }
        }
        
        return $domain;
    }

    public function addToCart($domain, $productData)
    {
        $site = Site::where('domain_name', $domain)->first();
        if (!$site) {
            return false;
        }

        $connectionName = $this->setupTenantConnection($site->db_name);
        
        // Validate product exists
        $product = DB::connection($connectionName)
            ->table('products')
            ->where('sku', $productData['sku'])
            ->first();

        if (!$product) {
            return false;
        }

        // Get or create cart_id
        $cartId = $this->getOrCreateCartId($domain);
        $cartKey = 'cart_' . $domain . '_' . $cartId;
        $cart = Cache::get($cartKey, []);

        // Check if product already in cart
        $productIndex = null;
        foreach ($cart as $index => $item) {
            if ($item['sku'] == $productData['sku']) {
                $productIndex = $index;
                break;
            }
        }

        $quantity = isset($productData['quantity']) ? (int)$productData['quantity'] : 1;
        
        // Get thumbnail from product images
        $thumbnail = $this->getProductThumbnail($product);
        
        if ($productIndex !== null) {
            // Update quantity if product already in cart
            $cart[$productIndex]['quantity'] += $quantity;
        } else {
            // Add new product to cart
            $cart[] = [
                'sku' => $productData['sku'],
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $quantity,
                'product_id' => $product->id,
                'thumbnail' => $thumbnail,
            ];
        }

        // Store cart in cache for 24 hours
        Cache::put($cartKey, $cart, now()->addHours(24));
        
        $result = $this->getCart($domain);
        $result['cart_id'] = $cartId;
        return $result;
    }

    public function updateCart($domain, $sku, $quantity)
    {
        $cartId = $this->getOrCreateCartId($domain);
        $cartKey = 'cart_' . $domain . '_' . $cartId;
        $cart = Cache::get($cartKey, []);

        if (empty($cart)) {
            return false;
        }

        // Find product in cart
        $productIndex = null;
        foreach ($cart as $index => $item) {
            if ($item['sku'] == $sku) {
                $productIndex = $index;
                break;
            }
        }

        if ($productIndex === null) {
            return false; // Product not found in cart
        }

        // Update quantity
        if ($quantity <= 0) {
            // Remove product if quantity is 0 or negative
            unset($cart[$productIndex]);
            $cart = array_values($cart);
        } else {
            $cart[$productIndex]['quantity'] = (int)$quantity;
        }

        Cache::put($cartKey, $cart, now()->addHours(24));
        
        $result = $this->getCart($domain);
        $result['cart_id'] = $cartId;
        return $result;
    }

    public function removeFromCart($domain, $sku)
    {
        $cartId = $this->getOrCreateCartId($domain);
        $cartKey = 'cart_' . $domain . '_' . $cartId;
        $cart = Cache::get($cartKey, []);

        $cart = array_filter($cart, function($item) use ($sku) {
            return $item['sku'] != $sku;
        });

        Cache::put($cartKey, array_values($cart), now()->addHours(24));
        
        $result = $this->getCart($domain);
        $result['cart_id'] = $cartId;
        return $result;
    }

    public function getCart($domain)
    {
        $site = Site::where('domain_name', $domain)->first();
        if (!$site) {
            return [
                'products' => [],
                'subtotal' => 0,
                'count' => 0,
                'cart_id' => null
            ];
        }

        $connectionName = $this->setupTenantConnection($site->db_name);
        $cartId = $this->getOrCreateCartId($domain);
        $cartKey = 'cart_' . $domain . '_' . $cartId;
        $cart = Cache::get($cartKey, []);

        // Ensure all cart items have thumbnail
        foreach ($cart as $index => $item) {
            if (!isset($item['thumbnail']) || empty($item['thumbnail'])) {
                // Reload product to get thumbnail
                $product = DB::connection($connectionName)
                    ->table('products')
                    ->where('id', $item['product_id'])
                    ->first();
                
                if ($product) {
                    $cart[$index]['thumbnail'] = $this->getProductThumbnail($product);
                } else {
                    $cart[$index]['thumbnail'] = '';
                }
            }
        }

        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += floatval($item['price']) * $item['quantity'];
        }

        return [
            'products' => array_values($cart),
            'subtotal' => $subtotal,
            'count' => count($cart),
            'cart_id' => $cartId
        ];
    }

    public function createOrder($domain, $cartToken, $orderData, CartService $cartService)
    {
        $site = Site::where('domain_name', $domain)->first();
        if (!$site) {
            return false;
        }

        $connectionName = $this->setupTenantConnection($site->db_name);

        // Get cart with details from CartService
        $cart = $cartService->getCartWithDetails($cartToken, $domain);

        if (empty($cart['items'])) {
            return false;
        }

        // Re-validate products, prices, inventory
        $validatedItems = [];
        $subtotal = 0;

        foreach ($cart['items'] as $item) {
            $product = DB::connection($connectionName)
                ->table('products')
                ->where('id', $item['product_id'])
                ->first();

            if (!$product) {
                continue; // Skip invalid products
            }

            // Validate inventory
            if ($product->inventory < $item['quantity']) {
                throw new \Exception("Product {$product->name} has insufficient inventory");
            }

            // Use current price from database (not from cache)
            $price = floatval($product->price);
            $itemTotal = $price * $item['quantity'];
            $subtotal += $itemTotal;

            $validatedItems[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $price,
                'quantity' => $item['quantity'],
            ];
        }

        if (empty($validatedItems)) {
            return false;
        }

        $shipping = isset($orderData['shipping']) ? floatval($orderData['shipping']) : 0;
        $discount = isset($orderData['discount']) ? floatval($orderData['discount']) : 0;
        $total = $subtotal + $shipping - $discount;

        // Generate order number
        $orderNo = $this->generateOrderNumber();

        // Create order
        $orderId = DB::connection($connectionName)->table('orders')->insertGetId([
            'order_no' => $orderNo,
            'name' => $orderData['name'] ?? '',
            'email' => $orderData['email'] ?? '',
            'phone' => $orderData['phone'] ?? '',
            'note' => $orderData['note'] ?? '',
            'address' => $orderData['address'] ?? '',
            'discount_coupon' => $orderData['discount_coupon'] ?? '',
            'currency' => $orderData['currency'] ?? '$',
            'discount' => $discount,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $total,
            'method' => $orderData['method'] ?? 'COD',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create order products
        foreach ($validatedItems as $item) {
            DB::connection($connectionName)->table('order_product')->insert([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Update inventory
            DB::connection($connectionName)
                ->table('products')
                ->where('id', $item['product_id'])
                ->decrement('inventory', $item['quantity']);
        }

        // Clear cart after order created
        $cartService->clearCart($cartToken);

        // Get order with products
        $order = DB::connection($connectionName)
            ->table('orders')
            ->where('id', $orderId)
            ->first();

        $orderProducts = DB::connection($connectionName)
            ->table('order_product')
            ->where('order_id', $orderId)
            ->get();

        $products = [];
        foreach ($orderProducts as $op) {
            $products[] = [
                'sku' => $op->sku,
                'name' => $op->name,
                'price' => $op->price,
                'quantity' => $op->quantity,
                'id' => $op->product_id,
            ];
        }

        return [
            'order_no' => $order->order_no,
            'name' => $order->name,
            'email' => $order->email,
            'phone' => $order->phone,
            'note' => $order->note,
            'address' => $order->address,
            'discount_coupon' => $order->discount_coupon,
            'currency' => $order->currency,
            'discount' => $order->discount,
            'subtotal' => $order->subtotal,
            'shipping' => $order->shipping,
            'total' => $order->total,
            'method' => $order->method,
            'status' => $order->status,
            'products' => $products,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'id' => $order->id,
        ];
    }

    private function generateOrderNumber()
    {
        return str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    private function getOrCreateCartId($domain)
    {
        // Try to get cart_id from header or request body first
        $cartId = request()->header('X-Cart-ID') ?? request()->input('cart_id');
        
        if ($cartId) {
            // Validate cart exists for this domain
            $cartKey = 'cart_' . $domain . '_' . $cartId;
            if (Cache::has($cartKey)) {
                return $cartId;
            }
        }
        
        // Try to get existing cart_id from mapping
        $identifier = $this->getSessionIdentifier();
        $mappingKey = 'cart_mapping_' . $domain . '_' . $identifier;
        $existingCartId = Cache::get($mappingKey);
        
        if ($existingCartId) {
            // Validate cart still exists
            $cartKey = 'cart_' . $domain . '_' . $existingCartId;
            if (Cache::has($cartKey)) {
                return $existingCartId;
            }
        }
        
        // Create new cart_id
        $cartId = $this->generateCartId();
        
        // Store mapping from identifier to cart_id for this domain
        Cache::put($mappingKey, $cartId, now()->addHours(24));
        
        return $cartId;
    }

    private function getSessionIdentifier()
    {
        // Try to get session ID from cookie
        $sessionId = request()->cookie('laravel_session');
        
        if ($sessionId) {
            return md5($sessionId);
        }
        
        // Use IP address + User-Agent as fallback
        $ip = request()->ip() ?? 'unknown';
        $userAgent = request()->userAgent() ?? 'unknown';
        return md5($ip . $userAgent);
    }

    private function generateCartId()
    {
        return uniqid('cart_', true);
    }

    private function getProductThumbnail($product)
    {
        $thumbnail = $product->images ?? '';
        // If thumbnail is json images, get first image
        if (!empty($thumbnail)) {
            $decodedImages = json_decode($thumbnail, true);
            if (is_array($decodedImages) && count($decodedImages) > 0) {
                $thumbnail = $decodedImages[0];
            }
        } else {
            $thumbnail = '';
        }
        return $thumbnail;
    }
}

