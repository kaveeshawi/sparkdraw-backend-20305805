<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id',
        'name',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
