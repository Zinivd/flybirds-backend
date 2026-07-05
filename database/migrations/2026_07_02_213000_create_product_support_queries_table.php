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
        Schema::create('product_support_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('cascade');
            $table->string('product_name')->nullable();
            $table->string('user_id')->nullable();
            $table->foreign('user_id')->references('user_id')->on('fly_users')->onDelete('set null');
            $table->string('user_name');
            $table->text('question');
            $table->text('reply')->nullable();
            $table->string('status')->default('Pending'); // Pending, Replied
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_support_queries');
    }
};
