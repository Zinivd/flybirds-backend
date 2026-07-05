<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fly_users', function (Blueprint $table) {
            // Define custom alphanumeric string as the primary index
            $table->string('user_id')->primary(); 
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password');
            // Explicit assignment states: user, superadmin, manager, finance
            $table->string('user_type')->default('user'); 
            $table->timestamp('otp_verified_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fly_users');
    }
};