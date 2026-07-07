<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_color_variant_id')->nullable();
            $table->unsignedBigInteger('product_size_stock_id')->nullable();
            $table->enum('type', ['cart', 'wishlist']);
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            // No FK on user_id — fly_users.user_id is not unique/indexed
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('product_color_variant_id')->references('id')->on('product_color_variants')->onDelete('cascade');
            $table->foreign('product_size_stock_id')->references('id')->on('product_size_stocks')->onDelete('cascade');

            $table->unique(
                ['user_id', 'product_id', 'product_color_variant_id', 'product_size_stock_id', 'type'],
                'cart_wishlist_unique_item'
            );
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_wishlists');
    }
};