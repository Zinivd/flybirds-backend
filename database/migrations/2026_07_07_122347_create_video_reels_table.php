<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('video_reels', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('description')->nullable();
    // Video Metadata from S3
    $table->string('file_name');
    $table->string('file_size');
    $table->string('video_url'); // The direct link stored in the DB
    $table->string('file_type');
    // Status
    $table->boolean('is_published')->default(false);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_reels');
    }
};
