<?php

namespace App\Service;

use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

class CartService
{
    private const CART_TTL = 60 * 60 * 24 * 3; // 3 days in seconds
    private const CART_KEY_PREFIX = 'cart:guest:';

    public function setupTenantConnection($dbName)
    {
        $connectionName = 'tenant';

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

    /**
     * Get cart from Redis
     */
    public function getCart($cartToken)
    {
        $cartKey = self::CART_KEY_PREFIX . $cartToken;
        $cartData = Cache::store('redis')->get($cartKey);
        
        if (!$cartData) {
            return [
                'items' => [],
                'updated_at' => now()->toIso8601String()
            ];
        }

        return is_array($cartData) ? $cartData : json_decode($cartData, true);
    }

    /**
     * Save cart to Redis with TTL
     */
    private function saveCart($cartToken, $cartData)
    {
        $cartKey = self::CART_KEY_PREFIX . $cartToken;
        $cartData['updated_at'] = now()->toIso8601String();
        Cache::store('redis')->put($cartKey, $cartData, self::CART_TTL);
    }

    /**
     * Add product to cart
     */
    public function addToCart($domain, $cartToken, $productId, $variantId, $qty)
    {
        $site = Site::where('domain_name', $domain)->first();
        if (!$site) {
            return false;
        }

        $connectionName = $this->setupTenantConnection($site->db_name);
        
        // Validate product exists
        $product = DB::connection($connectionName)
            ->table('products')
            ->where('id', $productId)
            ->first();

        if (!$product) {
            return false;
        }

        // Validate variant if provided (for future use)
        // For now, variant_id can be null
        if ($variantId !== null) {
            // Add variant validation here if needed
        }

        // Validate qty
        $qty = max(1, (int)$qty);

        // Get current cart
        $cart = $this->getCart($cartToken);

        // Check if product + variant already exists
        $itemIndex = null;
        foreach ($cart['items'] as $index => $item) {
            if ($item['product_id'] == $productId && 
                ($item['variant_id'] ?? null) == $variantId) {
                $itemIndex = $index;
                break;
            }
        }

        if ($itemIndex !== null) {
            // Add to existing quantity
            $cart['items'][$itemIndex]['qty'] += $qty;
        } else {
            // Add new item
            $cart['items'][] = [
                'product_id' => (int)$productId,
                'variant_id' => $variantId !== null ? (int)$variantId : null,
                'qty' => $qty
            ];
        }

        // Save cart
        $this->saveCart($cartToken, $cart);

        return $this->getCartWithDetails($cartToken, $domain);
    }

    /**
     * Update cart item quantity
     */
    public function updateCart($domain, $cartToken, $productId, $variantId, $qty)
    {
        $site = Site::where('domain_name', $domain)->first();
        if (!$site) {
            return false;
        }

        // Validate qty
        $qty = max(0, (int)$qty);

        // Get cart
        $cart = $this->getCart($cartToken);

        // Find item
        $itemIndex = null;
        foreach ($cart['items'] as $index => $item) {
            if ($item['product_id'] == $productId && 
                ($item['variant_id'] ?? null) == $variantId) {
                $itemIndex = $index;
                break;
            }
        }

        if ($itemIndex === null) {
            return false; // Item not found
        }

        if ($qty <= 0) {
            // Remove item
            unset($cart['items'][$itemIndex]);
            $cart['items'] = array_values($cart['items']);
        } else {
            // Update quantity
            $cart['items'][$itemIndex]['qty'] = $qty;
        }

        // Save cart
        $this->saveCart($cartToken, $cart);

        return $this->getCartWithDetails($cartToken, $domain);
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart($domain, $cartToken, $productId, $variantId)
    {
        $cart = $this->getCart($cartToken);

        $cart['items'] = array_filter($cart['items'], function($item) use ($productId, $variantId) {
            return !($item['product_id'] == $productId && 
                    ($item['variant_id'] ?? null) == $variantId);
        });

        $cart['items'] = array_values($cart['items']);

        $this->saveCart($cartToken, $cart);

        return $this->getCartWithDetails($cartToken, $domain);
    }

    /**
     * Clear cart
     */
    public function clearCart($cartToken)
    {
        $cartKey = self::CART_KEY_PREFIX . $cartToken;
        Cache::store('redis')->forget($cartKey);
        return true;
    }

    /**
     * Get cart with product details (for API response)
     */
    public function getCartWithDetails($cartToken, $domain)
    {
        $site = Site::where('domain_name', $domain)->first();
        if (!$site) {
            return [
                'items' => [],
                'subtotal' => 0,
                'count' => 0
            ];
        }

        $connectionName = $this->setupTenantConnection($site->db_name);
        $cart = $this->getCart($cartToken);

        $items = [];
        $subtotal = 0;

        foreach ($cart['items'] as $item) {
            $product = DB::connection($connectionName)
                ->table('products')
                ->where('id', $item['product_id'])
                ->first();

            if (!$product) {
                continue; // Skip invalid products
            }

            // Get current price from database (not from cache)
            $price = floatval($product->price);
            $qty = $item['qty'];
            $itemTotal = $price * $qty;
            $subtotal += $itemTotal;

            // Get thumbnail
            $thumbnail = $this->getProductThumbnail($product);

            $items[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => (string)$price,
                'quantity' => $qty,
                'thumbnail' => $thumbnail,
            ];
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'count' => count($items),
            'updated_at' => $cart['updated_at']
        ];
    }

    /**
     * Get product thumbnail from images field
     */
    private function getProductThumbnail($product)
    {
        $thumbnail = $product->images ?? '';
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
