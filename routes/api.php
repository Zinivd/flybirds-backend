<?php

use App\Http\Controllers\Api\Admin\AttributeController;
use App\Http\Controllers\Api\Admin\HomeBanner;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\ProductSupportQueryController;
use App\Http\Controllers\Api\Admin\SupportTicketController;
use App\Http\Controllers\Api\Admin\UserAddressController;
use App\Http\Controllers\Api\Admin\VideoReelController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\DelhiveryController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\Admin\cart_whishlist;

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

    Route::post('/forgot-password/send-otp', [AuthController::class, 'sendPasswordResetOtp']);

    Route::post('/forgot-password/verify-otp', [AuthController::class, 'verifyPasswordResetOtp']);
});


Route::prefix('admin')->group(function () {


    Route::post('/video-reels', [VideoReelController::class, 'store']);
    Route::get('/video-reels', [VideoReelController::class, 'index']);
    Route::patch('/video-reels/{id}/status', [VideoReelController::class, 'updateStatus']);


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

Route::prefix('payment')->group(function () {
    Route::post('/create-order', [PaymentController::class, 'createOrder']);
    Route::post('/verify', [PaymentController::class, 'verifyPayment']);
});

Route::prefix('delhivery')->group(function () {
    Route::get('/serviceability/{pincode}', [DelhiveryController::class, 'checkServiceability']);
    Route::get('/label', [DelhiveryController::class, 'getLabel']);
    Route::get('/track/{waybill}', [DelhiveryController::class, 'track']);
    Route::post('/shipment/create', [DelhiveryController::class, 'createShipment']);
    Route::get('/calculate-cost', [DelhiveryController::class, 'calculateCost']);
});

Route::get('/test-s3', function () {
    try {
        // Attempt to upload a dummy file
        Storage::disk('s3')->put('debug-test.txt', 'Connection Successful');

        // Return success if no exception was thrown
        return response()->json(['status' => 'success', 'message' => 'S3 is working!']);
    } catch (\Exception $e) {
        // Return the exact error from AWS
        return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
    }
});



Route::get('/debug-s3-upload', function () {
    try {
        // This attempts a direct write to the S3 bucket
        $result = Storage::disk('s3')->put('debug-test.txt', 'Test file content');

        if ($result) {
            return "Upload successful! Check your S3 bucket.";
        }
        return "Upload failed: disk::put returned false.";
    } catch (\Exception $e) {
        // This will print the actual error from AWS (e.g., Access Denied, Invalid Region)
        return "AWS Error: " . $e->getMessage();
    }
});


Route::prefix('banners')->group(function () {
    Route::get('/', [HomeBanner::class, 'index']);
    Route::post('/', [HomeBanner::class, 'store']);
    Route::get('/{id}', [HomeBanner::class, 'show']);
    Route::post('/{id}', [HomeBanner::class, 'update']); // POST for _method spoof / file upload
    Route::delete('/{id}', [HomeBanner::class, 'destroy']);
    Route::get('/{id}/download/{type}', [HomeBanner::class, 'download']);
});

// Cart
Route::post('admin/users/{userId}/cart', [cart_whishlist::class, 'addToCart']);
Route::get('admin/users/{userId}/cart', [cart_whishlist::class, 'listCart']);
Route::patch('admin/users/{userId}/cart/{id}', [cart_whishlist::class, 'updateCart']);
Route::delete('admin/users/{userId}/cart/{id}', [cart_whishlist::class, 'removeFromCart']);
Route::delete('admin/users/{userId}/cart', [cart_whishlist::class, 'clearCart']);

// Wishlist
Route::post('admin/users/{userId}/wishlist', [cart_whishlist::class, 'addToWishlist']);
Route::get('admin/users/{userId}/wishlist', [cart_whishlist::class, 'listWishlist']);
Route::delete('admin/users/{userId}/wishlist/{id}', [cart_whishlist::class, 'removeFromWishlist']);
Route::delete('admin/users/{userId}/wishlist/product/{productId}', [cart_whishlist::class, 'removeFromWishlistByProduct']);


Route::post('admin/users/{userId}/addresses', [UserAddressController::class, 'store']);
Route::get('admin/users/{userId}/addresses', [UserAddressController::class, 'getByUserId']);
Route::get('admin/users/{userId}/addresses/{addressId}', [UserAddressController::class, 'show']);
Route::patch('admin/users/{userId}/addresses/{addressId}', [UserAddressController::class, 'update']);
Route::delete('admin/users/{userId}/addresses/{addressId}', [UserAddressController::class, 'destroy']);