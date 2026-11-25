<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentReconciliation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';

    public const REASON_STRIPE_REFUNDS_PAGINATED = 'stripe_refunds_paginated';

    protected $fillable = [
        'provider',
        'provider_charge_id',
        'provider_payment_intent_id',
        'provider_event_id',
        'transaction_id',
        'payment_intent_id',
        'reason_code',
        'status',
        'detected_at',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
