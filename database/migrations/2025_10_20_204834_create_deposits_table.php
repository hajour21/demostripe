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
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->enum('status', ['pending', 'authorized', 'released', 'captured', 'failed', 'expired'])->default('pending');
            $table->unsignedInteger('authorized_amount_cents')->default(0);
            $table->unsignedInteger('captured_amount_cents')->default(0);
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
