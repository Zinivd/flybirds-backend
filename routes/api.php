<?php
use App\Http\Controllers\Api\Admin\AttributeController;
use App\Http\Controllers\Api\Admin\HomeBanner;
use App\Http\Controllers\Api\Admin\HomeCollectionController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\Admin\ProductSupportQueryController;
use App\Http\Controllers\Api\Admin\SupportTicketController;
use App\Http\Controllers\Api\Admin\UserAddressController;
use App\Http\Controllers\Api\Admin\VideoReelController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\RecentlyViewedController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\DelhiveryController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\Admin\cartwhishlist;
use App\Http\Controllers\Api\Admin\CartWishController;
use App\Http\Controllers\Api\Admin\ReportController;

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
        Route::get('/', [ProductController::class, 'index']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('/{id}', [ProductController::class, 'show']);
        Route::post('/{id}', [ProductController::class, 'update']);
        Route::delete('/{id}', [ProductController::class, 'destroy']);

        // Listing filters
        Route::get('/category/{categoryId}', [ProductController::class, 'getByCategory']);
        Route::get('/{id}/similar', [ProductController::class, 'similar']);

        // Status toggles
        Route::patch('/{id}/publish', [ProductController::class, 'togglePublish']);
        Route::patch('/{id}/today-sale', [ProductController::class, 'toggleTodaySale']);
        Route::patch('/{id}/flash-sale', [ProductController::class, 'updateFlashSale']);

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
        Route::patch('/{id}/unlock', [CustomerController::class, 'unlock']); // NEW
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('sales',        [ReportController::class, 'salesReport']);
        Route::get('inventory',    [ReportController::class, 'productInventoryReport']);
        Route::get('orders',       [ReportController::class, 'orderReport']);
        Route::get('transactions', [ReportController::class, 'transactionReport']);
    });

    // Orders
    // Route::prefix('orders')->group(function () {
    //     Route::get('/', [OrderController::class, 'index']);
    //     Route::get('/{id}', [OrderController::class, 'show']);
    //     Route::patch('/{id}/status', [OrderController::class, 'updateStatus']);
    //     Route::delete('/{id}', [OrderController::class, 'destroy']);
    // });

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
        Storage::disk('s3')->put('debug-test.txt', 'Connection Successful');
        return response()->json(['status' => 'success', 'message' => 'S3 is working!']);
    } catch (\Exception $e) {
        return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
    }
});

Route::get('/debug-s3-upload', function () {
    try {
        $result = Storage::disk('s3')->put('debug-test.txt', 'Test file content');
        if ($result) {
            return "Upload successful! Check your S3 bucket.";
        }
        return "Upload failed: disk::put returned false.";
    } catch (\Exception $e) {
        return "AWS Error: " . $e->getMessage();
    }
});

Route::prefix('banners')->group(function () {
    Route::get('/', [HomeBanner::class, 'index']);
    Route::post('/', [HomeBanner::class, 'store']);
    Route::get('/{id}', [HomeBanner::class, 'show']);
    Route::post('/{id}', [HomeBanner::class, 'update']);
    Route::delete('/{id}', [HomeBanner::class, 'destroy']);
    Route::get('/{id}/download/{type}', [HomeBanner::class, 'download']);
});

// Cart
Route::post('admin/users/{userId}/cart', [CartWishController::class, 'addToCart']);
Route::get('admin/users/{userId}/cart', [CartWishController::class, 'listCart']);
Route::patch('admin/users/{userId}/cart/{id}', [CartWishController::class, 'updateCart']);
Route::delete('admin/users/{userId}/cart/{id}', [CartWishController::class, 'removeFromCart']);
Route::delete('admin/users/{userId}/cart', [CartWishController::class, 'clearCart']);

// Wishlist
Route::post('admin/users/{userId}/wishlist', [CartWishController::class, 'addToWishlist']);
Route::get('admin/users/{userId}/wishlist', [CartWishController::class, 'listWishlist']);
Route::delete('admin/users/{userId}/wishlist/{id}', [CartWishController::class, 'removeFromWishlist']);
Route::delete('admin/users/{userId}/wishlist/product/{productId}', [CartWishController::class, 'removeFromWishlistByProduct']);

Route::post('admin/users/{userId}/addresses', [UserAddressController::class, 'store']);
Route::get('admin/users/{userId}/addresses', [UserAddressController::class, 'getByUserId']);
Route::get('admin/users/{userId}/addresses/{addressId}', [UserAddressController::class, 'show']);
Route::patch('admin/users/{userId}/addresses/{addressId}', [UserAddressController::class, 'update']);
Route::delete('admin/users/{userId}/addresses/{addressId}', [UserAddressController::class, 'destroy']);

Route::get('/admin/home/best-collections', [HomeCollectionController::class, 'bestCollections']);
Route::get('/admin/home/best-sellers', [HomeCollectionController::class, 'bestSellers']);

Route::post('/orders/checkout', [OrderController::class, 'checkout']);
Route::get('/orders', [OrderController::class, 'index']);
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::get('/users/{userId}/orders', [OrderController::class, 'myOrders']);
Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus']);
Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
Route::get('/orders/check-stock', [OrderController::class, 'checkStock']);

Route::post('/recently-viewed', [RecentlyViewedController::class, 'store']);
Route::get('/recently-viewed/{userId}', [RecentlyViewedController::class, 'index']);
Route::delete('/recently-viewed/{userId}/{productId}', [RecentlyViewedController::class, 'destroy']);
Route::delete('/recently-viewed/{userId}', [RecentlyViewedController::class, 'clear']);


Route::post('/reviews', [ProductReviewController::class, 'store']);
Route::get('/reviews', [ProductReviewController::class, 'index']);
Route::get('/reviews/{id}', [ProductReviewController::class, 'show']);
Route::patch('/reviews/{id}', [ProductReviewController::class, 'update']);
Route::delete('/reviews/{id}', [ProductReviewController::class, 'destroy']);
 
Route::get('/users/{userId}/reviews', [ProductReviewController::class, 'byCustomer']);
Route::get('/products/{productId}/reviews', [ProductReviewController::class, 'byProduct']);
 

Route::get('/orders/{id}/invoice', [OrderController::class, 'invoice']);

Route::post('/orders/{id}/invoice-mail', [OrderController::class, 'invoiceMail']);