<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Service\CartService;

class CartController extends Controller
{
    public function __construct(CartService $cartService) 
    {
        $this->cartService = $cartService;
    }

    /**
     * GET /api/cart - Get cart with details
     */
    public function getCart(Request $request)
    {
        $domain = $this->cartService->getDomainFromRequest();
        // Lấy cart_token từ request đã được middleware xử lý hoặc từ cookie
        $cartToken = $request->get('cart_token') 
            ?? $request->cookie('cart_token')
            ?? $request->header('X-Cart-Token')
            ?? $request->input('cart_token');

        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        if (empty($cartToken)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart token not found',
                'data' => null
            ], 400);
        }

        $result = $this->cartService->getCartWithDetails($cartToken, $domain);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }

    /**
     * POST /api/cart/add - Add product to cart
     */
    public function addToCart(Request $request)
    {
        $domain = $this->cartService->getDomainFromRequest();
        // Lấy cart_token từ request đã được middleware xử lý hoặc từ cookie
        $cartToken = $request->get('cart_token') 
            ?? $request->cookie('cart_token')
            ?? $request->header('X-Cart-Token')
            ?? $request->input('cart_token');
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        if (empty($cartToken)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart token not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'product_id' => 'required|integer',
            'variant_id' => 'nullable|integer',
            'qty' => 'required|integer|min:1',
        ]);

        $result = $this->cartService->addToCart(
            $domain,
            $cartToken,
            $request->input('product_id'),
            $request->input('variant_id'),
            $request->input('qty')
        );

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

    /**
     * PUT /api/cart/update - Update cart item quantity
     */
    public function updateCart(Request $request)
    {
        $domain = $this->cartService->getDomainFromRequest();
        // Lấy cart_token từ request đã được middleware xử lý hoặc từ cookie
        $cartToken = $request->get('cart_token') 
            ?? $request->cookie('cart_token')
            ?? $request->header('X-Cart-Token')
            ?? $request->input('cart_token');
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        if (empty($cartToken)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart token not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'product_id' => 'required|integer',
            'variant_id' => 'nullable|integer',
            'qty' => 'required|integer|min:0',
        ]);

        $result = $this->cartService->updateCart(
            $domain,
            $cartToken,
            $request->input('product_id'),
            $request->input('variant_id'),
            $request->input('qty')
        );

        if ($result === false) {
            return response()->json([
                'success' => false,
                'status' => 404,
                'message' => 'Item not found in cart',
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

    /**
     * DELETE /api/cart/remove - Remove item from cart
     */
    public function removeFromCart(Request $request)
    {
        $domain = $this->cartService->getDomainFromRequest();
        // Lấy cart_token từ request đã được middleware xử lý hoặc từ cookie
        $cartToken = $request->get('cart_token') 
            ?? $request->cookie('cart_token')
            ?? $request->header('X-Cart-Token')
            ?? $request->input('cart_token');
        
        if (empty($domain)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Domain not found',
                'data' => null
            ], 400);
        }

        if (empty($cartToken)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart token not found',
                'data' => null
            ], 400);
        }

        $request->validate([
            'product_id' => 'required|integer',
            'variant_id' => 'nullable|integer',
        ]);

        $result = $this->cartService->removeFromCart(
            $domain,
            $cartToken,
            $request->input('product_id'),
            $request->input('variant_id')
        );

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => null,
            'data' => $result
        ]);
    }

    /**
     * DELETE /api/cart/clear - Clear cart
     */
    public function clearCart(Request $request)
    {
        // Lấy cart_token từ request đã được middleware xử lý hoặc từ cookie
        $cartToken = $request->get('cart_token') 
            ?? $request->cookie('cart_token')
            ?? $request->header('X-Cart-Token')
            ?? $request->input('cart_token');
        
        if (empty($cartToken)) {
            return response()->json([
                'success' => false,
                'status' => 400,
                'message' => 'Cart token not found',
                'data' => null
            ], 400);
        }

        $this->cartService->clearCart($cartToken);

        return response()->json([
            'success' => true,
            'status' => 200,
            'message' => 'Cart cleared successfully',
            'data' => null
        ]);
    }
}
