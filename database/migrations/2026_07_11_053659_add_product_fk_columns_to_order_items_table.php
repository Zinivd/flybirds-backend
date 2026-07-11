<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->after('order_table_id');
            $table->unsignedBigInteger('product_color_variant_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('product_size_stock_id')->nullable()->after('product_color_variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'product_color_variant_id', 'product_size_stock_id']);
        });
    }
};