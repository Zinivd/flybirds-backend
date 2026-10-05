<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('seo_title', 255)->nullable()->after('order_level');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->text('seo_keywords')->nullable()->after('seo_description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_description', 'seo_keywords']);
        });
    }
};
