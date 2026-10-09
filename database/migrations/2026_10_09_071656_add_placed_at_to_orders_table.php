<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {   // use your actual orders table name
            $table->timestamp('placed_at')->nullable()->after('payment_status')->index();
        });

        // Backfill: existing paid orders and COD orders count as placed.
        DB::table('orders')
            ->whereNull('placed_at')
            ->where(function ($q) {
                $q->where('payment_status', 'Paid')
                    ->orWhereRaw('LOWER(payment_method) = ?', ['cod']);
            })
            ->update(['placed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('placed_at');
        });
    }
};
