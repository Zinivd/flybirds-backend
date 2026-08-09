<?php
// database/migrations/xxxx_xx_xx_add_ewbn_to_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // 12-digit GST e-way bill number, generated externally
            // (government EWB portal / GSP) and attached to the shipment
            // via Delhivery's ewaybill/update API. Nullable — most orders
            // won't have one until they cross the value threshold and get
            // a waybill assigned.
            $table->string('ewbn', 12)->nullable()->after('awb_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ewbn');
        });
    }
};