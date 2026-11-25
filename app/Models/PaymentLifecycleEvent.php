<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentLifecycleEvent extends Model
{
    use HasFactory;

    public const TYPE_FULL_REFUND = 'full_refund';
    public const TYPE_PARTIAL_REFUND = 'partial_refund';
    public const TYPE_CHARGEBACK = 'chargeback';
    public const TYPE_DISPUTE_OPENED = 'dispute_opened';
    public const TYPE_DISPUTE_WON = 'dispute_won';
    public const TYPE_DISPUTE_LOST = 'dispute_lost';
    public const TYPE_REVERSED_PAYMENT = 'reversed_payment';

    public const STATUS_RECORDED = 'recorded';
    public const STATUS_OPEN = 'open';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'provider_lifecycle_id',
        'provider_transaction_id',
        'transaction_id',
        'payment_intent_id',
        'subscription_id',
        'user_id',
        'lifecycle_type',
        'status',
        'amount_minor',
        'currency',
        'requires_admin_review',
        'reviewed_at',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'requires_admin_review' => 'boolean',
        'meta' => 'array',
        'reviewed_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
