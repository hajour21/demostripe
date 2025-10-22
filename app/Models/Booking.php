<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Deposit; // <-- AJOUT

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_name',
        'property_name',
        'check_in_at',
        'check_out_at',
        'deposit_amount_cents',
        'status',
    ];

    protected $casts = [
        'check_in_at'  => 'datetime',
        'check_out_at' => 'datetime',
    ];

    public function deposit()
    {
        return $this->hasOne(Deposit::class);
    }
}
