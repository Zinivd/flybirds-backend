<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recently_viewed_products', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');                 // FK to fly_users.user_id (string format like FYB-USR-xxxx)
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('product_name');             // snapshot at time of viewing
            $table->string('category_name')->nullable(); // snapshot at time of viewing
            $table->timestamp('viewed_at');              // updated every time the product is re-viewed
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('fly_users')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');

            // One row per user+product — re-viewing updates viewed_at instead of duplicating
            $table->unique(['user_id', 'product_id']);

            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recently_viewed_products');
    }
};