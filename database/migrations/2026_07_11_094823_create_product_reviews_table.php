<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * Create with:
 *   php artisan make:migration create_product_reviews_table
 * Then paste this content into the generated file, or drop this
 * file directly into database/migrations/ (rename the timestamp
 * prefix to today's date so it runs after products/fly_users).
 * ═══════════════════════════════════════════════════════════════
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();

            // Customer who wrote the review — matches the string user_id
            // pattern used elsewhere (e.g. OrderController -> fly_users.user_id)
            $table->string('user_id');
            $table->foreign('user_id')
                ->references('user_id')->on('fly_users')
                ->onDelete('cascade');

            // Product being reviewed
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->onDelete('cascade');

            $table->string('title', 255);
            $table->text('description')->nullable();

            // 1–5 star rating. Range is enforced at the application/validation
            // layer (see ProductReviewController). Uncomment the raw check
            // constraint below if you're on MySQL 8.0.16+ and want a DB-level
            // guarantee too.
            $table->unsignedTinyInteger('rating');

            $table->timestamps();

            // One review per customer per product. Remove this line if you
            // want to allow multiple reviews from the same customer.
            $table->unique(['user_id', 'product_id']);

            $table->index('product_id');
            $table->index('user_id');
            $table->index('rating');
        });

        // Optional DB-level guard (MySQL 8.0.16+). Safe to leave commented
        // out if you're unsure of your MySQL version or use another driver.
        // DB::statement('ALTER TABLE product_reviews ADD CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};