<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DriveFolder extends Model
{
    use HasAgencyScope, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'owner_id',
        'name',
        'description',
        'icon',
        'visibility',
        'roles',
        'allow_download',
        'allow_upload',
    ];

    protected $casts = [
        'roles' => 'array',
        'allow_download' => 'boolean',
        'allow_upload' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class, 'folder_id');
    }
}
