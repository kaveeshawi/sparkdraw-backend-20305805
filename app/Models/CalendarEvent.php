<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    use HasAgencyScope;

    public const TYPES = [
        'meetings',
        'tasks',
        'milestones',
        'deadlines',
        'personal',
        'birthdays',
    ];

    public const TONES = ['pink', 'blue', 'purple', 'peach'];

    protected $fillable = [
        'agency_id',
        'created_by',
        'project_id',
        'title',
        'subtitle',
        'description',
        'type',
        'tone',
        'starts_at',
        'ends_at',
        'all_day',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at'   => 'datetime',
            'all_day'   => 'boolean',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function defaultTone(string $type): string
    {
        return match ($type) {
            'meetings' => 'pink',
            'milestones' => 'purple',
            'tasks', 'deadlines' => 'peach',
            'birthdays' => 'pink',
            default => 'blue',
        };
    }
}
