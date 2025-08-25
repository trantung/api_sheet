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