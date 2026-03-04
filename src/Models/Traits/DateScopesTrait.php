<?php

namespace Topoff\Messenger\Models\Traits;

use Illuminate\Support\Facades\Date;

trait DateScopesTrait
{
    /**
     * Scope a query to today.
     *
     *
     * @return mixed
     */
    protected function scopeToday($query)
    {
        return $query->whereDate('created_at', Date::today());
    }

    /**
     * Scope a query to this month.
     *
     *
     * @return mixed
     */
    protected function scopeThisMonth($query)
    {
        return $query->where('created_at', '>', Date::today()->startOfDay()->startOfMonth())->where('created_at', '<', Date::today()->endOfDay()->endOfMonth());
    }
}
