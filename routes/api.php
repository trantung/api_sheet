<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\TempController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ApiTestController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\NavbarController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CartController;
use App\Http\Middleware\CartTokenMiddleware;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/site/index', [SiteController::class, 'index'])->name('api.site.index');
Route::post('/product/search', [SiteController::class, 'productSearch'])->name('api.search');
Route::post('/product/detail', [SiteController::class, 'productDetail'])->name('api.productDetail');
Route::post('/pages/detail', [SiteController::class, 'pageDetail'])->name('api.pageDetail');

// Email subscribe/feedback
Route::post('/web/web-email', [EmailController::class, 'store'])->name('api.web.email');

// Cart routes - Guest cart với cart_token
Route::middleware([CartTokenMiddleware::class])->group(function () {
    Route::post('/cart', [CartController::class, 'getCart'])->name('api.cart.get');
    Route::post('/cart/add', [CartController::class, 'addToCart'])->name('api.cart.add');
    Route::post('/cart/update', [CartController::class, 'updateCart'])->name('api.cart.update');
    Route::post('/cart/remove', [CartController::class, 'removeFromCart'])->name('api.cart.remove');
    Route::post('/cart/clear', [CartController::class, 'clearCart'])->name('api.cart.clear');
});

// Order routes (legacy - có thể giữ lại hoặc refactor sau)
Route::middleware([CartTokenMiddleware::class])->group(function () {
    Route::post('/order/create', [OrderController::class, 'createOrder'])->name('api.order.create');
});