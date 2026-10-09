<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY delivery_status ENUM('Pending','Packed','Shipped','In Transit','Out For Delivery','Delivered','RTO','Cancelled','Refunded') NOT NULL DEFAULT 'Pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders MODIFY delivery_status ENUM('Pending','Packed','Shipped','Out For Delivery','Delivered','RTO','Cancelled','Refunded') NOT NULL DEFAULT 'Pending'");
    }
};
