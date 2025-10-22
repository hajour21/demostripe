<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['type', 'payload_json', 'processed_at', 'related_payment_intent_id', 'status'];
    protected $casts = ['processed_at' => 'datetime'];
}
