<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the bookings page returns a successful response.
     */
    public function test_the_bookings_page_returns_a_successful_response(): void
    {
        // Créer quelques données de test
        \App\Models\Booking::factory()->count(3)->create();
        
        $response = $this->get('/demo/bookings');
        $response->assertStatus(200);
    }

    /**
     * Test that the checkout page returns a successful response.
     */
    public function test_the_checkout_page_returns_a_successful_response(): void
    {
        $booking = \App\Models\Booking::factory()->create();
        
        $response = $this->get("/demo/checkout/{$booking->id}");
        $response->assertStatus(200);
    }
}