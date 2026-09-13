<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeOverviewController extends Controller
{
    use ApiResponse;

    private const AVAILABILITY_SORT = [
        'available' => 0,
        'busy'      => 1,
        'away'      => 2,
        'offline'   => 3,
    ];

    // GET /api/v1/time-overview
    public function index(Request $request): JsonResponse
    {
        $agencyId    = $request->user()->agency_id;
        $periodStart = now()->copy()->startOfMonth();
        $periodEnd   = now()->copy()->endOfMonth();

        $billableHours = (float) TimeLog::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereBetween('logged_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->sum('hours');

        $onDutyHours = $this->calculateOnDutyHours($agencyId, $periodStart, $periodEnd);

        return $this->success([
            'billable_hours' => round($billableHours, 1),
            'on_duty_hours'  => round($onDutyHours, 1),
            'period'         => [
                'start' => $periodStart->toDateString(),
                'end'   => $periodEnd->toDateString(),
            ],
        ]);
    }

    // GET /api/v1/time/team-presence
    public function teamPresence(Request $request): JsonResponse
    {
        $agencyId  = $request->user()->agency_id;
        $today     = now()->toDateString();
        $weekStart = now()->copy()->startOfWeek()->toDateString();
        $weekEnd   = now()->copy()->endOfWeek()->toDateString();

        $members = User::where('agency_id', $agencyId)
            ->where('role', '!=', 'client')
            ->get();

        $openSessions = WorkSession::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereNull('clock_out_at')
            ->get()
            ->keyBy('user_id');

        $taskCounts = Task::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereIn('status', ['todo', 'in_progress', 'in_review'])
            ->whereNull('deleted_at')
            ->whereNotNull('assignee_id')
            ->selectRaw('assignee_id, COUNT(*) as cnt')
            ->groupBy('assignee_id')
            ->pluck('cnt', 'assignee_id');

        $hoursTodayByUser = TimeLog::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereDate('logged_date', $today)
            ->selectRaw('user_id, SUM(hours) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $hoursWeekByUser = TimeLog::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereBetween('logged_date', [$weekStart, $weekEnd])
            ->selectRaw('user_id, SUM(hours) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $memberData = $members->map(function (User $user) use ($openSessions, $taskCounts, $hoursTodayByUser, $hoursWeekByUser) {
            $session     = $openSessions->get($user->id);
            $availability = $user->availability ?: 'offline';

            return [
                'user_id'         => $user->id,
                'name'            => $user->name,
                'role'            => $user->role,
                'job_title'       => $user->job_title,
                'availability'    => $availability,
                'is_clocked_in'   => $session !== null,
                'clock_in_at'     => $session?->clock_in_at?->toIso8601String(),
                'hours_today'     => round((float) ($hoursTodayByUser[$user->id] ?? 0), 1),
                'hours_this_week' => round((float) ($hoursWeekByUser[$user->id] ?? 0), 1),
                'active_tasks'    => (int) ($taskCounts[$user->id] ?? 0),
            ];
        })->sort(function (array $a, array $b) {
            if ($a['is_clocked_in'] !== $b['is_clocked_in']) {
                return $b['is_clocked_in'] <=> $a['is_clocked_in'];
            }

            $aRank = self::AVAILABILITY_SORT[$a['availability']] ?? 99;
            $bRank = self::AVAILABILITY_SORT[$b['availability']] ?? 99;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return strcasecmp($a['name'], $b['name']);
        })->values();

        $summary = [
            'on_duty'   => $memberData->where('is_clocked_in', true)->count(),
            'available' => $memberData->where('availability', 'available')->count(),
            'busy'      => $memberData->where('availability', 'busy')->count(),
            'away'      => $memberData->where('availability', 'away')->count(),
            'offline'   => $memberData->where('availability', 'offline')->count(),
        ];

        $hoursByDate = TimeLog::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereBetween('logged_date', [$weekStart, $weekEnd])
            ->selectRaw('DATE(logged_date) as d, SUM(hours) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $hoursByDay = [];
        $cursor = Carbon::parse($weekStart)->startOfDay();
        $endDay = Carbon::parse($weekEnd)->startOfDay();
        while ($cursor->lte($endDay)) {
            $date = $cursor->toDateString();
            $hoursByDay[] = [
                'date'  => $date,
                'label' => $cursor->format('D'),
                'hours' => round((float) ($hoursByDate[$date] ?? 0), 1),
            ];
            $cursor->addDay();
        }

        return $this->success([
            'summary' => $summary,
            'members' => $memberData,
            'charts'  => [
                'hours_by_day' => $hoursByDay,
            ],
        ]);
    }

    private function calculateOnDutyHours(int $agencyId, Carbon $periodStart, Carbon $periodEnd): float
    {
        $sessions = WorkSession::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->where('clock_in_at', '<=', $periodEnd)
            ->where(function ($query) use ($periodStart) {
                $query->whereNull('clock_out_at')
                    ->orWhere('clock_out_at', '>=', $periodStart);
            })
            ->get();

        $totalSeconds = 0;

        foreach ($sessions as $session) {
            $sessionStart = $session->clock_in_at;
            $sessionEnd   = $session->clock_out_at ?? now();

            $overlapStart = $sessionStart->greaterThan($periodStart) ? $sessionStart : $periodStart;
            $overlapEnd   = $sessionEnd->lessThan($periodEnd) ? $sessionEnd : $periodEnd;

            if ($overlapStart->lt($overlapEnd)) {
                $totalSeconds += $overlapStart->diffInSeconds($overlapEnd);
            }
        }

        return $totalSeconds / 3600;
    }
}
