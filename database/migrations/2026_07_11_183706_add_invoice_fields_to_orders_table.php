<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_number', 30)->nullable()->unique()->after('order_id');
            $table->timestamp('invoice_date')->nullable()->after('invoice_number');
            $table->string('awb_number', 50)->nullable()->after('invoice_date');
        });

        // Sequence table used to hand out invoice numbers atomically,
        // mirroring how order_sequences already works for order IDs.
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('current_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['invoice_number', 'invoice_date', 'awb_number']);
        });

        Schema::dropIfExists('invoice_sequences');
    }
};