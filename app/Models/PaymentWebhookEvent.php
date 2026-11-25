<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasFactory;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'provider_reference',
        'status',
        'processing_started_at',
        'locked_until',
        'processing_token',
        'attempts',
        'last_error',
        'payload_hash',
        'processed_at',
        'dead_lettered_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'processing_started_at' => 'datetime',
        'locked_until' => 'datetime',
        'processed_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
    ];

    public function hasActiveLease(?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return $this->status === self::STATUS_PROCESSING
            && $this->locked_until !== null
            && $now->lessThan($this->locked_until);
    }

    public function isLeaseExpired(?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();

        return $this->status === self::STATUS_PROCESSING
            && ($this->locked_until === null || $now->greaterThanOrEqualTo($this->locked_until));
    }
}
