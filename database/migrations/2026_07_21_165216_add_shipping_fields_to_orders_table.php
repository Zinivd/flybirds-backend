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
    Schema::table('orders', function (Blueprint $table) {
        if (!Schema::hasColumn('orders', 'shipping_pincode')) {
            $table->string('shipping_pincode', 6)->nullable()->after('shipping_address');
        }
        if (!Schema::hasColumn('orders', 'shipping_city')) {
            $table->string('shipping_city')->nullable()->after('shipping_pincode');
        }
        if (!Schema::hasColumn('orders', 'shipping_state')) {
            $table->string('shipping_state')->nullable()->after('shipping_city');
        }
        if (!Schema::hasColumn('orders', 'awb_number')) {
            $table->string('awb_number')->nullable()->after('shipping_state');
        }
        if (!Schema::hasColumn('orders', 'shipment_status')) {
            $table->string('shipment_status')->nullable()->after('awb_number'); // created, cancelled, failed
        }
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            //
        });
    }
};
