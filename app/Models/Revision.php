<?php

namespace App\Models;

use App\Models\User;
use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Revision extends Model
{
    use HasAgencyScope;

    protected $fillable = [
        'agency_id', 'project_id', 'client_id', 'submitted_by_id',
        'round_number', 'feedback_text', 'ai_ticket_json', 'status',
    ];

    protected $casts = [
        'ai_ticket_json' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }
}
