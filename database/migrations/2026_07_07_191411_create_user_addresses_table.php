<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_addresses', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('user_id');
    $table->enum('address_type', ['home', 'work', 'other'])->default('home');
    $table->string('full_name');
    $table->string('phone');
    $table->string('address_line_1');
    $table->string('address_line_2')->nullable();
    $table->string('city');
    $table->string('state');
    $table->string('postal_code');
    $table->string('country')->default('India');
    $table->boolean('is_default')->default(false);
    $table->timestamps();

    // No FK on user_id — validated in controller
    $table->index('user_id');
});
    }

    public function down(): void
    {
        Schema::dropIfExists('user_addresses');
    }
};