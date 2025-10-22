<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    protected $fillable = [
        'booking_id',
        'stripe_payment_intent_id',
        'status',
        'authorized_amount_cents',
        'captured_amount_cents',
        'authorized_at',
        'released_at',
        'captured_at',
        'last_error'
    ];
    protected $casts = [
        'authorized_at' => 'datetime',
        'released_at' => 'datetime',
        'captured_at' => 'datetime'
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
