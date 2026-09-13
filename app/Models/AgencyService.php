<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyService extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'name',
        'description',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(AgencyServicePackage::class)->orderBy('name');
    }
}
