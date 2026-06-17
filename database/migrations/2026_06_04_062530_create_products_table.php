<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('unit');                   // KG, Pc, etc.
            $table->decimal('weight', 8, 2)->default(0);
            $table->integer('min_qty')->default(1);
            $table->text('tags')->nullable();
            $table->integer('estimate_shipping_days')->nullable();
            $table->longText('description')->nullable();

            // Category
            $table->foreignId('category_id')->constrained()->onDelete('cascade');

            // Base Pricing
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->string('discount_type')->nullable();        // 'flat' or 'percent'
            $table->date('discount_start_date')->nullable();
            $table->date('discount_end_date')->nullable();
            $table->integer('reward_points')->default(0);

            // Flash Sale
            $table->boolean('is_flash_sale')->default(false);
            $table->string('flash_sale_title')->nullable();
            $table->decimal('flash_sale_discount', 8, 2)->default(0);
            $table->string('flash_sale_discount_type')->nullable(); // 'flat' or 'percent'

            // Status
            $table->boolean('is_today_sale')->default(false);
            $table->boolean('is_published')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};