<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_wishlist_data', function (Blueprint $table) {
            $table->foreignId('family_color_id')
                ->nullable()
                ->after('product_color_variant_id')
                ->constrained('family_colors')
                ->nullOnDelete();

            $table->foreignId('family_color_child_id')
                ->nullable()
                ->after('family_color_id')
                ->constrained('family_color_children')
                ->nullOnDelete();
        });
    }

    public function down(): void
{
    Schema::table('cart_wishlist_data', function (Blueprint $table) {
        if (Schema::hasColumn('cart_wishlist_data', 'family_color_id')) {
            try {
                $table->dropForeign(['family_color_id']);
            } catch (\Exception $e) {
                // constraint didn't exist — ignore
            }
        }
        if (Schema::hasColumn('cart_wishlist_data', 'family_color_child_id')) {
            try {
                $table->dropForeign(['family_color_child_id']);
            } catch (\Exception $e) {
                // constraint didn't exist — ignore
            }
        }
        $table->dropColumn(array_filter([
            Schema::hasColumn('cart_wishlist_data', 'family_color_id') ? 'family_color_id' : null,
            Schema::hasColumn('cart_wishlist_data', 'family_color_child_id') ? 'family_color_child_id' : null,
        ]));
    });
}
};
