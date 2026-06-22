<?php

namespace App\Models;

use App\Services\SentimentService;
use App\Traits\HasAgencyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasAgencyScope, SoftDeletes;

    protected $fillable = [
        'agency_id', 'client_id', 'name', 'type', 'description', 'status', 'priority',
        'budget', 'estimated_hours', 'start_date', 'end_date', 'color',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'budget'     => 'decimal:2',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    // Members explicitly assigned to the project at creation/edit time —
    // distinct from task assignees, which ProjectResource also folds in.
    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_team_members')->withTimestamps();
    }

    public function timeLogs(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(TimeLog::class, Task::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectEvent::class);
    }

    public function latestHealthScore(): HasOne
    {
        // orderByDesc instead of latestOfMany() — avoids JOIN ambiguity in SQLite tests.
        // Eager loading picks the first (latest) result per project after DESC sort.
        return $this->hasOne(HealthScore::class)->orderByDesc('computed_at');
    }

    public function healthScores(): HasMany
    {
        return $this->hasMany(HealthScore::class);
    }

    public function upsellSuggestions(): HasMany
    {
        return $this->hasMany(UpsellSuggestion::class);
    }

    // Collects everything the C3 Health Score algorithm (FastAPI /health-score) needs.
    public function getAIMetrics(): array
    {
        $revisionCount = $this->revisions()->count();

        $agencyProjectCount = static::withoutAgencyScope()
            ->where('agency_id', $this->agency_id)
            ->count();
        $agencyRevisionCount = Revision::withoutAgencyScope()
            ->where('agency_id', $this->agency_id)
            ->count();
        $avgRevisionRate = $agencyProjectCount > 0
            ? $agencyRevisionCount / $agencyProjectCount
            : 0.0;

        $actualHours = (float) $this->timeLogs()->sum('hours');
        $hoursBurnRatio = $this->estimated_hours > 0
            ? $actualHours / $this->estimated_hours
            : 0.0;

        $approvalLagAvg = (float) ($this->approvals()
            ->whereNotNull('approval_lag_hours')
            ->avg('approval_lag_hours') ?? 0.0);

        $deadlineSlips = $this->milestones()
            ->where('due_date', '<', now())
            ->where('status', '!=', 'completed')
            ->count();

        $last7 = $this->messages()->where('created_at', '>=', now()->subDays(7))->count();
        $prev7 = $this->messages()
            ->where('created_at', '<', now()->subDays(7))
            ->where('created_at', '>=', now()->subDays(14))
            ->count();
        $messageVelocityChange = $prev7 > 0
            ? ($last7 - $prev7) / $prev7
            : 0.0;

        // Client-only sentiment (same source as the sentiment timeline), last 30 days
        $clientContactId = $this->client?->contact_user_id;
        $clientMessages = $clientContactId
            ? $this->messages()
                ->where('sender_id', $clientContactId)
                ->whereNotNull('sentiment_score')
                ->where('created_at', '>=', now()->subDays(30))
                ->orderByDesc('created_at')
                ->get()
            : collect();

        $sentimentTrend = $clientMessages->isNotEmpty()
            ? (float) $clientMessages->avg('sentiment_score')
            : 0.0;

        $clientAtRisk = $clientContactId
            && SentimentService::isClientAtRisk($clientContactId, $this->id);

        $totalTasks = $this->tasks()->count();
        $doneTasks = $this->tasks()->where('status', 'done')->count();
        $completionPct = $totalTasks > 0 ? $doneTasks / $totalTasks : 0.0;

        return [
            'project_id'              => $this->id,
            'revision_count'          => $revisionCount,
            'avg_revision_rate'       => round($avgRevisionRate, 2),
            'hours_burn_ratio'        => round($hoursBurnRatio, 4),
            'approval_lag_avg_hours'  => round($approvalLagAvg, 2),
            'deadline_slips'          => $deadlineSlips,
            'message_velocity_change' => round($messageVelocityChange, 4),
            'sentiment_trend'         => round($sentimentTrend, 4),
            'completion_pct'          => round($completionPct, 4),
            'client_at_risk'          => $clientAtRisk,
        ];
    }

    /** Feature vector for C2 ML upsell model (FastAPI POST /upsell). */
    public function getUpsellMetrics(): array
    {
        $metrics = $this->getAIMetrics();
        $latestHealth = $this->relationLoaded('latestHealthScore')
            ? $this->latestHealthScore
            : $this->latestHealthScore()->first();

        $healthScore = $latestHealth?->score ?? match ($latestHealth?->flag ?? 'amber') {
            'green' => 75.0,
            'amber' => 50.0,
            'red'   => 25.0,
            default => 50.0,
        };

        if ($this->budget > 0) {
            $invoiced = (float) $this->invoices()->sum('amount');
            $budgetUsedPct = min(1.0, $invoiced / (float) $this->budget);
        } else {
            $budgetUsedPct = min(1.0, $metrics['hours_burn_ratio']);
        }

        $lastRevision = $this->revisions()->latest('created_at')->first();
        $daysSinceLastRevision = $lastRevision
            ? (float) $lastRevision->created_at->diffInDays(now())
            : 14.0;

        $projectAgeDays = $this->start_date
            ? (int) $this->start_date->diffInDays(now())
            : 0;

        return [
            'completion_pct'           => $metrics['completion_pct'],
            'health_score'             => (float) $healthScore,
            'revision_count'           => $metrics['revision_count'],
            'approval_lag_hrs'         => $metrics['approval_lag_avg_hours'],
            'budget_used_pct'          => round($budgetUsedPct, 4),
            'sentiment_avg'            => $metrics['sentiment_trend'],
            'project_age_days'         => max(0, $projectAgeDays),
            'invoice_count'            => max(1, $this->invoices()->count()),
            'days_since_last_revision' => $daysSinceLastRevision,
        ];
    }
}
