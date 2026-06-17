<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each color can have multiple sizes, each size has its own stock + SKU
        // Stock is a cache here — inventory module is source of truth
        Schema::create('product_size_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_color_variant_id')
                  ->constrained('product_color_variants')
                  ->onDelete('cascade');
            $table->string('size');              // "S", "M", "L", "XL", "XXL", "42", etc.
            $table->string('sku')->unique();
            $table->decimal('price', 10, 2);     // Each size can have different price
            $table->integer('stock')->default(0); // Cache — managed by Inventory module
            $table->timestamps();

            // A color cannot have the same size twice
            $table->unique(['product_color_variant_id', 'size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_size_stocks');
    }
};