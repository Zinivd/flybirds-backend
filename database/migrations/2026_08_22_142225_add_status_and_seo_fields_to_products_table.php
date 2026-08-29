<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Soft "active/inactive" flag — replaces hard delete
            $table->boolean('is_active')->default(true)->after('is_published');

            // Spotlight image URL (stored alongside gallery/thumbnail images)
            $table->string('spotlight_image')->nullable()->after('description');

            // SEO fields
            $table->string('seo_title')->nullable()->after('spotlight_image');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->json('seo_keywords')->nullable()->after('seo_description');
        });
    }

   public function down(): void
{
    Schema::table('products', function (Blueprint $table) {
        $columnsToDrop = array_filter([
            'is_active',
            'spotlight_image',
            'seo_title',
            'seo_description',
            'seo_keywords',
        ], fn ($col) => Schema::hasColumn('products', $col));

        if (!empty($columnsToDrop)) {
            $table->dropColumn($columnsToDrop);
        }
    });
}
};
