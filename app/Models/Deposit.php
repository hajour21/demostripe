<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    // Constantes de statut
    const STATUS_PENDING = 'pending';
    const STATUS_AUTHORIZED = 'authorized';
    const STATUS_RELEASED = 'released';
    const STATUS_CAPTURED = 'captured';
    const STATUS_FAILED = 'failed';
    const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'booking_id',
        'stripe_payment_intent_id',
        'status',
        'authorized_amount_cents',
        'captured_amount_cents',
        'authorized_at',
        'released_at',
        'captured_at',
        'last_error',
        'test_mode',
        'release_reason',
        'capture_reason',
    ];

    protected $casts = [
        'authorized_at' => 'datetime',
        'released_at' => 'datetime',
        'captured_at' => 'datetime',
        'test_mode' => 'boolean',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function canBeReleased(): bool
    {
        return in_array($this->status, [self::STATUS_AUTHORIZED, self::STATUS_PENDING]) &&
            $this->captured_amount_cents === 0;
    }

    public function canBeCaptured(int $amountCents = null): bool
    {
        if (!in_array($this->status, [self::STATUS_AUTHORIZED])) {
            return false;
        }

        if ($amountCents && $amountCents > $this->authorized_amount_cents) {
            return false;
        }

        $remainingAmount = $this->authorized_amount_cents - $this->captured_amount_cents;
        if ($amountCents && $amountCents > $remainingAmount) {
            return false;
        }

        return true;
    }

    public function getAuthorizedAmountAttribute(): float
    {
        return $this->authorized_amount_cents / 100;
    }

    public function getCapturedAmountAttribute(): float
    {
        return $this->captured_amount_cents / 100;
    }

    public function getRemainingAmountAttribute(): float
    {
        return ($this->authorized_amount_cents - $this->captured_amount_cents) / 100;
    }

    public function requiresAction(): bool
    {
        return $this->status === self::STATUS_PENDING && !empty($this->stripe_payment_intent_id);
    }

    // Méthode pour obtenir tous les statuts valides
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_AUTHORIZED,
            self::STATUS_RELEASED,
            self::STATUS_CAPTURED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ];
    }
}
