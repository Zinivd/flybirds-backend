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
        $table->string('waybill')->nullable()->index()->after('id');
        $table->string('delhivery_status')->nullable();
        $table->boolean('is_pincode_serviceable')->nullable();
        $table->timestamp('shipped_at')->nullable();
        $table->timestamp('delivered_at')->nullable();
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropColumn(['waybill', 'delhivery_status', 'is_pincode_serviceable', 'shipped_at', 'delivered_at']);
    });
}
};
