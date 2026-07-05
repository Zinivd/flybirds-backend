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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->unique();
            $table->string('user_id')->nullable();
            $table->foreign('user_id')->references('user_id')->on('fly_users')->onDelete('set null');
            $table->string('user_name');
            $table->text('question');
            $table->text('customer_reply')->nullable();
            $table->text('admin_reply')->nullable();
            $table->string('status')->default('Pending'); // Pending, Replied, Solved, Not Solved
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
