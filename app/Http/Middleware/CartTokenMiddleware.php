<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CartTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get cart_token from cookie, header, or body (theo thứ tự ưu tiên)
        $cartToken = $request->cookie('cart_token') 
            ?? $request->header('X-Cart-Token')
            ?? $request->input('cart_token');

        // Generate new cart_token if not exists or invalid
        if (!$cartToken || !$this->isValidUuid($cartToken)) {
            $cartToken = (string) Str::uuid();
        }

        // Attach cart_token to request (để Controller có thể lấy được)
        $request->merge(['cart_token' => $cartToken]);

        $response = $next($request);

        // Set cart_token cookie nếu chưa có trong request
        // Chỉ set khi chưa có cookie (lần đầu tiên)
        if (!$request->cookie('cart_token')) {
            $response->cookie(
                'cart_token', 
                $cartToken, 
                60 * 24 * 3,  // 3 days
                '/',           // Path
                null,          // Domain (null = current domain)
                false,         // Secure (false = http và https)
                false          // HttpOnly (false = JS có thể đọc)
            );
        }

        return $response;
    }

    private function isValidUuid($uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
    }
}

