<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_size_stocks', function (Blueprint $table) {
            // Drops the unique index on `sku`.
            // Laravel's default index name is `<table>_<column>_unique`,
            // i.e. `product_size_stocks_sku_unique` — matches the name
            // in your error message.
            $table->dropUnique('product_size_stocks_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_size_stocks', function (Blueprint $table) {
            // Reversible: re-adds the unique constraint if you roll back.
            // NOTE: this will fail on rollback if duplicate SKUs exist
            // in the table at that time — you'd need to clean them up first.
            $table->unique('sku', 'product_size_stocks_sku_unique');
        });
    }
};
