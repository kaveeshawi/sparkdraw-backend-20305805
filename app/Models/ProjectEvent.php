<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// APPEND-ONLY — never update or delete rows in this table.
// Use ProjectEvent::log() to insert; never call save() on an existing instance.
class ProjectEvent extends Model
{
    use HasAgencyScope;

    // No updated_at column exists on this table
    const UPDATED_AT = null;

    protected $fillable = ['agency_id', 'project_id', 'event_type', 'metadata'];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public static function log(int $agencyId, ?int $projectId, string $eventType, array $metadata = []): self
    {
        return static::create([
            'agency_id'  => $agencyId,
            'project_id' => $projectId,
            'event_type' => $eventType,
            'metadata'   => $metadata,
        ]);
    }

    // Guard against accidental mutations
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ProjectEvent is append-only. Records cannot be updated.');
        }
        return parent::save($options);
    }

    public function delete(): bool|null
    {
        throw new \LogicException('ProjectEvent is append-only. Records cannot be deleted.');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
