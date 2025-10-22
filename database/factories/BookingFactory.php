<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFactory extends Factory
{
    public function definition(): array
    {
        // J-2 / J+1 pour coller au scénario (caution à J-2)
        $checkIn  = now()->addDays(2);
        $checkOut = (clone $checkIn)->addDays(fake()->numberBetween(2, 5));

        return [
            'guest_name'            => fake()->firstName(),
            'property_name'         => fake()->randomElement(['Villa Azur', 'Loft City', 'Riad Medina']),
            'check_in_at'           => $checkIn,
            'check_out_at'          => $checkOut,
            'deposit_amount_cents'  => fake()->randomElement([20000, 30000, 50000]), // 200–500 €
            'status'                => 'pending',
        ];
    }
}
