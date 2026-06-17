<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Images belong to a specific COLOR of a product
        // type: 'gallery' (600x600) or 'thumbnail' (300x300)
        Schema::create('product_color_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_color_variant_id')
                  ->constrained('product_color_variants')
                  ->onDelete('cascade');
            $table->string('image_url');
            $table->enum('type', ['gallery', 'thumbnail'])->default('gallery');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_color_images');
    }
};