<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   // database/migrations/xxxx_xx_xx_create_delhivery_shipments_table.php
public function up(): void
{
    Schema::create('delhivery_shipments', function (Blueprint $table) {
        $table->id();
        $table->string('order_id')->unique();
        $table->string('waybill')->nullable()->index();
        $table->string('status')->default('pending'); // pending, created, cancelled, failed
        $table->string('payment_mode');
        $table->decimal('total_amount', 10, 2);
        $table->decimal('cod_amount', 10, 2)->default(0);
        $table->json('request_payload')->nullable();
        $table->json('response_payload')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delhivery_shipments');
    }
};
