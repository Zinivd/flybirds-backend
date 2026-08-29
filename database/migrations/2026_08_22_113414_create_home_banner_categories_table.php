<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_banner_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_banner_id')->constrained('home_banners')->onDelete('cascade');
            $table->foreignId('category_id')->constrained('categories')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['home_banner_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_banner_categories');
    }
};
