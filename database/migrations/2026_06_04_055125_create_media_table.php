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
    Schema::create('media', function (Blueprint $table) {
        $table->id();
        $table->string('file_name');
        $table->string('file_size'); // e.g., "2048 KB"
        $table->string('file_url');  // Full S3 URL
        $table->string('mime_type'); // e.g., "image/png"
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
