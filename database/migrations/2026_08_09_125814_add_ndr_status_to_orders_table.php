<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Null = no NDR / not tracked as undelivered.
            // Set by DelhiveryController::syncNdrStatus() when Delhivery's
            // tracking response reports an undelivered attempt (StatusType 'UD').
            // Cleared back to null once updateNDR() successfully resolves it
            // (RE-ATTEMPT/DEFERRED keep it open with a new remark; RTO clears
            // it because the order moves to a terminal RTO state instead).
            $table->string('ndr_status')->nullable()->after('delhivery_status');
            $table->string('ndr_reason')->nullable()->after('ndr_status');
            $table->timestamp('ndr_updated_at')->nullable()->after('ndr_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ndr_status', 'ndr_reason', 'ndr_updated_at']);
        });
    }
};