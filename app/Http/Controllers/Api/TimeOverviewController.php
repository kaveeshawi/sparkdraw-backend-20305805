<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\TimeLog;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeOverviewController extends Controller
{
    use ApiResponse;

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
