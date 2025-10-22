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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('guest_name');
            $table->string('property_name');
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at');
            $table->unsignedInteger('deposit_amount_cents'); // ex 30000 pour 300€
            $table->string('status')->default('pending');     // pending|checked_in|checked_out|closed (indicatif)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
