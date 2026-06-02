<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('type'); // 'physical'
        $table->foreignId('parent_id')->nullable()->constrained('categories')->onDelete('cascade');
        $table->integer('order_level')->default(0);
        $table->string('banner_path')->nullable();
        $table->string('icon_path')->nullable();
        $table->string('cover_path')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
