<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Service\OrderService;
use App\Service\CartService;

class OrderController extends Controller
{
    public function __construct(OrderService $orderService, CartService $cartService) 
    {
        $this->orderService = $orderService;
        $this->cartService = $cartService;
    }

    public function addToCart(Request $request)
    {
        $domain = $this->orderService->getDomainFromRequest();
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'sku' => 'required|string',
            'quantity' => 'sometimes|integer|min:1',
        ]);

        $productData = [
            'sku' => $request->input('sku'),
            'quantity' => $request->input('quantity', 1),
        ];

        $result = $this->orderService->addToCart($domain, $productData);

        if ($result === false) {
            return response()->json([
                'success' => false,
                'status' => 404,
                'message' => 'Product not found',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }

    public function updateCart(Request $request)
    {
        $domain = $this->orderService->getDomainFromRequest();
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'sku' => 'required|string',
            'quantity' => 'required|integer|min:0',
        ]);

        $result = $this->orderService->updateCart($domain, $request->input('sku'), $request->input('quantity'));

        if ($result === false) {
            return response()->json([
                'success' => false,
                'status' => 404,
                'message' => 'Product not found in cart or cart is empty',
                'data' => null
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }

    public function removeFromCart(Request $request)
    {
        $domain = $this->orderService->getDomainFromRequest();
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'sku' => 'required|string',
        ]);

        $result = $this->orderService->removeFromCart($domain, $request->input('sku'));

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }

    public function getCart(Request $request)
    {
        $domain = $this->orderService->getDomainFromRequest();
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        $result = $this->orderService->getCart($domain);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }

    public function createOrder(Request $request)
    {
        $domain = $this->orderService->getDomainFromRequest();
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'address' => 'required|string',
            'phone' => 'nullable|string',
            'note' => 'nullable|string',
            'discount_coupon' => 'nullable|string',
            'currency' => 'sometimes|string',
            'shipping' => 'sometimes|numeric',
            'discount' => 'sometimes|numeric',
            'method' => 'sometimes|string',
        ]);

        $cartToken = $request->input('cart_token');
        
        if (empty($cartToken)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart token not found',
                'data' => null
            ], 400);
        }

        $orderData = $request->only([
            'name', 'email', 'phone', 'note', 'address',
            'discount_coupon', 'currency', 'discount', 'shipping', 'method'
        ]);

        try {
            $result = $this->orderService->createOrder($domain, $cartToken, $orderData, $this->cartService);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => $e->getMessage(),
                'data' => null
            ], 400);
        }

        if ($result === false) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart is empty or site not found',
                'data' => null
            ], 400);
        }

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }
}

