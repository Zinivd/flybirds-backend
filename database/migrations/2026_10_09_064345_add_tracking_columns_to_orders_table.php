<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('expected_delivery_at')->nullable();
            $table->timestamp('last_tracked_at')->nullable();
            $table->string('last_scan_remarks')->nullable();
            $table->string('last_scan_location')->nullable();
            $table->json('shipment_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'expected_delivery_at', 'last_tracked_at',
                'last_scan_remarks', 'last_scan_location', 'shipment_snapshot',
            ]);
        });
    }
};
