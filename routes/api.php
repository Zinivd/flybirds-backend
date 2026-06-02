<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Api\Admin\CategoryController;

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


Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/categories', [CategoryController::class, 'store']);
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