<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_colors', function (Blueprint $table) {
            $table->id();
            $table->string('name');   // e.g. "Blue Family"
            $table->string('code');   // e.g. "#0000FF" (parent/representative code)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_colors');
    }
};
