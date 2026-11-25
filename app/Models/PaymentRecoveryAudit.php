<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRecoveryAudit extends Model
{
    use HasFactory;

    public const TARGET_WEBHOOK_EVENT = 'webhook_event';
    public const TARGET_RECONCILIATION = 'reconciliation';
    public const TARGET_LIFECYCLE_EVENT = 'lifecycle_event';

    protected $fillable = [
        'actor_user_id',
        'target_type',
        'target_id',
        'action',
        'reason',
        'previous_status',
        'new_status',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
