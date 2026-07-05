<?php

use App\Http\Controllers\Api\Admin\AttributeController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\ProductSupportQueryController;
use App\Http\Controllers\Api\Admin\SupportTicketController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\ReviewController;

Route::prefix('auth')->group(function () {
    // Regular Customer Registration Pipeline
    Route::post('/register/init', [AuthController::class, 'registerInit']);
    Route::post('/register/verify', [AuthController::class, 'registerVerify']);

    // Administrative Personnel Registration Pipeline (Public Initial Configuration Gateways)
    Route::post('/admin/register/init', [AuthController::class, 'adminRegisterInit']);
    Route::post('/admin/register/verify', [AuthController::class, 'adminRegisterVerify']);

    // Core Unified Login Gate (Pass name('login') to prevent RouteNotException errors)
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    // Protected User Profile Modifier Route Group
    Route::middleware('auth:api')->group(function () {
        Route::put('/profile/update/{user_id}', [AuthController::class, 'updateProfile']);
        Route::get('/user/info/{user_id}', [AuthController::class, 'getUserInfo']);
    });
});


Route::prefix('admin')->group(function () {

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{id}', [CategoryController::class, 'show']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::post('/{id}', [CategoryController::class, 'update']);
        Route::delete('/{id}', [CategoryController::class, 'destroy']);
    });



    // Attributes & Media
    Route::prefix('attributes')->group(function () {
        Route::get('/colors', [AttributeController::class, 'indexColors']);
        Route::post('/colors', [AttributeController::class, 'storeColors']);
        Route::delete('/colors/{id}', [AttributeController::class, 'deleteColor']);
        Route::get('/media', [AttributeController::class, 'listFiles']);
        Route::post('/media/upload', [AttributeController::class, 'uploadFiles']);
        Route::delete('/media/{id}', [AttributeController::class, 'deleteFile']);
    });

    // Products
    Route::prefix('products')->group(function () {

        // CRUD
        Route::get('/', [ProductController::class, 'index']);    // GET    /admin/products
        Route::post('/', [ProductController::class, 'store']);    // POST   /admin/products
        Route::get('/{id}', [ProductController::class, 'show']);     // GET    /admin/products/{id}
        Route::post('/{id}', [ProductController::class, 'update']);   // POST   /admin/products/{id}
        Route::delete('/{id}', [ProductController::class, 'destroy']); // DELETE /admin/products/{id}

        // Listing filters
        Route::get('/category/{categoryId}', [ProductController::class, 'getByCategory']); // GET /admin/products/category/{id}
        Route::get('/{id}/similar', [ProductController::class, 'similar']);        // GET /admin/products/{id}/similar

        // Status toggles
        Route::patch('/{id}/publish', [ProductController::class, 'togglePublish']);    // PATCH
        Route::patch('/{id}/today-sale', [ProductController::class, 'toggleTodaySale']); // PATCH
        Route::patch('/{id}/flash-sale', [ProductController::class, 'updateFlashSale']); // PATCH

        // Delete color variant (cascades its images + sizes)
        Route::delete(
            '/{productId}/colors/{colorVariantId}',
            [ProductController::class, 'destroyColorVariant']
        );

        // Delete individual size from a color
        Route::delete(
            '/{productId}/colors/{colorVariantId}/sizes/{sizeStockId}',
            [ProductController::class, 'destroySizeStock']
        );
    });

    // Products by Category (public listing)
    Route::get('/products/category/{categoryId}', [ProductController::class, 'getByCategory']);


    Route::prefix('inventory')->group(function () {
        Route::get('/', [InventoryController::class, 'index']);
        Route::get('/product/{productId}', [InventoryController::class, 'getByProduct']);
        Route::get('/size/{size}', [InventoryController::class, 'getBySize']);
        Route::post('/update-by-product/{productId}', [InventoryController::class, 'updateStockByProduct']);
    });

    // Reviews
    Route::prefix('reviews')->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::post('/', [ReviewController::class, 'store']);
        Route::delete('/{id}', [ReviewController::class, 'destroy']);
    });

    // Customers
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::delete('/{id}', [CustomerController::class, 'destroy']);
        Route::patch('/{id}/lock', [CustomerController::class, 'lock']);
    });

    // Orders
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/{id}', [OrderController::class, 'show']);
        Route::patch('/{id}/status', [OrderController::class, 'updateStatus']);
        Route::delete('/{id}', [OrderController::class, 'destroy']);
    });

    // Support Product Queries
    Route::prefix('support-products')->group(function () {
        Route::get('/', [ProductSupportQueryController::class, 'index']);
        Route::get('/{id}', [ProductSupportQueryController::class, 'show']);
        Route::patch('/{id}/reply', [ProductSupportQueryController::class, 'reply']);
        Route::delete('/{id}', [ProductSupportQueryController::class, 'destroy']);
    });

    // Support Tickets
    Route::prefix('support-tickets')->group(function () {
        Route::get('/', [SupportTicketController::class, 'index']);
        Route::get('/{id}', [SupportTicketController::class, 'show']);
        Route::patch('/{id}/reply', [SupportTicketController::class, 'reply']);
        Route::patch('/{id}/status', [SupportTicketController::class, 'updateStatus']);
        Route::delete('/{id}', [SupportTicketController::class, 'destroy']);
    });
});


Route::get('/test-s3', function () {
    try {
        // 1. Attempt to write a test file to S3
        Storage::disk('s3')->put('test-flybirds.txt', 'Flybirds S3 Connection Successful!');

        // 2. Check if the file exists
        if (Storage::disk('s3')->exists('test-flybirds.txt')) {
            // 3. Generate a temporary URL for the file
            $url = Storage::disk('s3')->temporaryUrl('test-flybirds.txt', now()->addMinutes(5));

            return response()->json([
                'status' => 'success',
                'message' => 'Connection verified!',
                'file_url' => $url
            ]);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 500);
    }
});
