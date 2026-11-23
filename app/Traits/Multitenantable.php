<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait Multitenantable
{
    protected static function booted()
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Auth::hasUser()) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', Auth::user()->tenant_id);
            }
        });

        static::saving(function ($model) {
            if (Auth::hasUser() && empty($model->tenant_id)) {
                $model->tenant_id = Auth::user()->tenant_id;
            }
        });
    }
}
