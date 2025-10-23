<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Booking;

class BookingSeeder extends Seeder
{
    public function run(): void
    {
        Booking::create([
            'guest_name' => 'Alice',
            'property_name' => 'Villa Azur',
            'check_in_at' => now()->addDays(2),
            'check_out_at' => now()->addDays(5),
            'deposit_amount_cents' => 30000,
            'status' => 'confirmed',
        ]);

        Booking::create([
            'guest_name' => 'Bob',
            'property_name' => 'Loft City',
            'check_in_at' => now()->addDays(2),
            'check_out_at' => now()->addDays(4),
            'deposit_amount_cents' => 50000,
            'status' => 'confirmed',
        ]);

        Booking::create([
            'guest_name' => 'Charlie',
            'property_name' => 'Riad Medina',
            'check_in_at' => now()->addDays(1),
            'check_out_at' => now()->addDays(3),
            'deposit_amount_cents' => 20000,
            'status' => 'completed',
        ]);
    }
}
