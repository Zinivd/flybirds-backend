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
    Schema::create('inventory_logs', function (Blueprint $table) {
    $table->id();
    // Foreign keys for clean relational data
    $table->foreignId('product_id')->constrained()->onDelete('cascade');
    $table->foreignId('color_id')->constrained()->onDelete('cascade');
    $table->string('size'); 
    
    // Audit data
    $table->integer('previous_stock');
    $table->integer('adjustment_amount'); // +50 or -5
    $table->integer('new_stock');
    $table->string('reason'); 
    
    // Track WHO and WHEN
    $table->unsignedBigInteger('admin_id'); // Use integer ID here!
    $table->timestamps();
});
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_logs');
    }
};
