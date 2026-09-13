<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyServicePackage extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'agency_service_id',
        'name',
        'includes',
        'price',
        'duration_hours',
        'suggested_roles',
    ];

    protected $casts = [
        'price'           => 'float',
        'duration_hours'  => 'integer',
        'suggested_roles' => 'array',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(AgencyService::class, 'agency_service_id');
    }
}
