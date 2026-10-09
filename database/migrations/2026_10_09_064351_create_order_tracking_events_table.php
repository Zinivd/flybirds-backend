<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_table_id')->constrained('orders')->cascadeOnDelete();
            $table->string('awb_number')->index();
            $table->string('status');
            $table->string('status_code')->nullable();
            $table->string('remarks')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('scanned_at');
            $table->string('hash', 40);
            $table->timestamps();

            $table->unique(['order_table_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking_events');
    }
};
