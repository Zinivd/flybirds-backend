<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_color_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_color_id')
                  ->constrained('family_colors')
                  ->onDelete('cascade');
            $table->string('name');   // e.g. "Sky Blue"
            $table->string('code');   // e.g. "#87CEEB"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_color_children');
    }
};
