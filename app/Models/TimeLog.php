<?php

namespace App\Models;

use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeLog extends Model
{
    use HasAgencyScope;

    protected $fillable = ['agency_id', 'task_id', 'user_id', 'hours', 'logged_date', 'notes'];

    protected $casts = [
        'logged_date' => 'date',
        'hours'       => 'decimal:2',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
