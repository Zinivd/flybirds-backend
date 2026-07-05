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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('customer_id')->nullable();
            $table->foreign('customer_id')->references('user_id')->on('fly_users')->onDelete('set null');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('seller_name')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('shipping', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->string('delivery_status')->default('Pending'); // Pending, Shipped, Delivered, Cancelled, Refunded
            $table->string('payment_method');
            $table->string('payment_status')->default('Pending'); // Paid, Pending, Refunded, Failed
            $table->text('shipping_address')->nullable();
            $table->text('billing_address')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_table_id')->constrained('orders')->onDelete('cascade');
            $table->string('product_name');
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->decimal('price', 12, 2);
            $table->integer('quantity');
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
