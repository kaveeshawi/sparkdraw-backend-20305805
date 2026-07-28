<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthScore extends Model
{
    // No HasAgencyScope — always accessed via Project relationship which is already scoped.

    // Only computed_at exists — no created_at / updated_at
    public $timestamps = false;

    protected $fillable = ['agency_id', 'project_id', 'score', 'flag', 'reasons', 'computed_at'];

    protected $casts = [
        'reasons'     => 'array',
        'computed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
