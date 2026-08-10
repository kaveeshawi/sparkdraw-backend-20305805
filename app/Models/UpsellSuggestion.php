<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpsellSuggestion extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id', 'project_id', 'service_type',
        'confidence', 'admin_status', 'client_status',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
