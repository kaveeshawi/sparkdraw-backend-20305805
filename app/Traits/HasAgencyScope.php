<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasAgencyScope
{
    protected static function bootHasAgencyScope(): void
    {
        static::addGlobalScope('agency', function (Builder $builder) {
            if (auth()->check()) {
                $table = $builder->getModel()->getTable();
                $builder->where("{$table}.agency_id", auth()->user()->agency_id);
            }
        });
    }

    // Convenience method for the seeder and internal commands to skip the scope
    public static function withoutAgencyScope(): Builder
    {
        return static::withoutGlobalScope('agency');
    }
}
