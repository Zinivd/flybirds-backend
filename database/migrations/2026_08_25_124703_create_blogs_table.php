<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('sub_title')->nullable();
            $table->text('description_1')->nullable();
            $table->text('description_2')->nullable();
            $table->text('description_3')->nullable();
            $table->string('cover_image_path')->nullable(); // S3 key
            $table->unsignedBigInteger('product_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->dateTime('published_at')->nullable(); // set when toggled to published
            $table->timestamps(); // created_at = "date/time blog was added"

            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->onDelete('set null');

            $table->index('is_published');
        });
    }

    public function down()
    {
        Schema::dropIfExists('blogs');
    }
};
