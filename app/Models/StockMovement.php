<?php

namespace App\Models;

use App\Traits\Multitenantable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class StockMovement extends Model
{
    use HasFactory, BelongsToTenant, Multitenantable;

    protected $table = 'stock_movements';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'reference_type',
        'reference_id',
        'note',
        'user_id',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'quantity_change' => 'float',
        'quantity_before' => 'float',
        'quantity_after' => 'float',
        'unit_cost' => 'float',
        'reference_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id')->withoutGlobalScope('tenant');
    }
}
