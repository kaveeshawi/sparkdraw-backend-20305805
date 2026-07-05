<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id', 'project_id', 'uploader_id',
        'file_path', 'original_name', 'version', 'is_deliverable',
    ];

    protected $casts = [
        'is_deliverable' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'deliverable_id');
    }
}
