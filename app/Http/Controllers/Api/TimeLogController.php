<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TimeLog\StoreTimeLogRequest;
use App\Http\Resources\TimeLogResource;
use App\Http\Traits\ApiResponse;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeLogController extends Controller
{
    use ApiResponse;

    // POST /api/v1/projects/{project}/tasks/{task}/time-logs
    // A user can only log their own time — the user_id is always forced to auth user.
    public function store(StoreTimeLogRequest $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $user = $request->user();

        $log = TimeLog::create([
            'agency_id'   => $user->agency_id,
            'task_id'     => $task->id,
            'user_id'     => $user->id,   // ALWAYS the authenticated user — never from request
            'hours'       => $request->hours,
            'logged_date' => $request->logged_date,
            'notes'       => $request->notes,
        ]);

        // Keep actual_hours on the task as the authoritative SUM of all time logs
        $totalActualHours = TimeLog::withoutGlobalScope('agency')
            ->where('task_id', $task->id)
            ->sum('hours');

        $task->update(['actual_hours' => (int) ceil($totalActualHours)]);

        ProjectEvent::log($user->agency_id, $project->id, 'time_logged', [
            'task_title'          => $task->title,
            'hours'               => (float) $request->hours,
            'total_actual_hours'  => (float) $totalActualHours,
            'logged_by'           => $user->id,
        ]);

        $log->load('user:id,name');

        return $this->created(new TimeLogResource($log), 'Time logged successfully.');
    }

    // GET /api/v1/projects/{project}/tasks/{task}/time-logs
    public function index(Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $logs = $task->timeLogs()
            ->with('user:id,name')
            ->orderByDesc('logged_date')
            ->get();

        return $this->success([
            'time_logs'    => TimeLogResource::collection($logs),
            'total_hours'  => (float) $logs->sum('hours'),
        ]);
    }

    // GET /api/v1/projects/{project}/time-summary
    // Returns total hours per user per task for the month. Feeds Module E workload view.
    public function summary(Request $request, Project $project): JsonResponse
    {
        $month = $request->query('month', now()->format('Y-m'));

        // Validate month format (YYYY-MM)
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->error('Invalid month format. Use YYYY-MM.', [], 422);
        }

        [$year, $monthNum] = explode('-', $month);

        // Fetch all time logs for this project in the given month
        $logs = TimeLog::withoutGlobalScope('agency')
            ->whereHas('task', fn($q) => $q->where('project_id', $project->id)->whereNull('deleted_at'))
            ->whereYear('logged_date', $year)
            ->whereMonth('logged_date', $monthNum)
            ->where('agency_id', $project->agency_id)
            ->with('user:id,name', 'task:id,title,project_id')
            ->get();

        // Group by user
        $byUser = $logs->groupBy('user_id')->map(function ($userLogs, $userId) {
            $user       = $userLogs->first()->user;
            $totalHours = $userLogs->sum('hours');

            $tasks = $userLogs->groupBy('task_id')->map(function ($taskLogs) {
                $task = $taskLogs->first()->task;
                return [
                    'task_id'    => $task?->id,
                    'task_title' => $task?->title,
                    'hours'      => (float) $taskLogs->sum('hours'),
                ];
            })->values();

            return [
                'user_id'     => $userId,
                'user_name'   => $user?->name,
                'total_hours' => (float) $totalHours,
                'tasks'       => $tasks,
            ];
        })->values();

        return $this->success([
            'month'      => $month,
            'project_id' => $project->id,
            'breakdown'  => $byUser,
        ]);
    }

    // GET /api/v1/projects/time-summary
    // Agency-wide workload: heatmap + per-member capacity for the current week.
    public function agencyWorkload(Request $request): JsonResponse
    {
        $agencyId  = $request->user()->agency_id;
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd   = now()->endOfWeek()->toDateString();

        // Heatmap — hours per day over last 21 days (single grouped query)
        $days      = 21;
        $startDate = now()->subDays($days - 1)->toDateString();
        $hoursByDate = TimeLog::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereDate('logged_date', '>=', $startDate)
            ->selectRaw('DATE(logged_date) as d, SUM(hours) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date  = now()->subDays($i)->toDateString();
            $hours = (float) ($hoursByDate[$date] ?? 0);
            $values[] = min(100, (int) round(($hours / 8) * 100));
        }

        // Per-member breakdown (batched queries)
        $members = User::where('agency_id', $agencyId)
            ->where('role', '!=', 'client')
            ->orderBy('name')
            ->get();

        $taskCounts = Task::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereIn('status', ['todo', 'in_progress', 'in_review'])
            ->whereNull('deleted_at')
            ->whereNotNull('assignee_id')
            ->selectRaw('assignee_id, COUNT(*) as cnt')
            ->groupBy('assignee_id')
            ->pluck('cnt', 'assignee_id');

        $hoursByUser = TimeLog::withoutGlobalScope('agency')
            ->where('agency_id', $agencyId)
            ->whereBetween('logged_date', [$weekStart, $weekEnd])
            ->selectRaw('user_id, SUM(hours) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $memberData = $members->map(function (User $user) use ($taskCounts, $hoursByUser) {
            $hoursThisWeek = (float) ($hoursByUser[$user->id] ?? 0);
            $capacityPct   = min(100, (int) round(($hoursThisWeek / 40) * 100));

            return [
                'user_id'         => $user->id,
                'name'            => $user->name,
                'role'            => $user->role,
                'active_tasks'    => (int) ($taskCounts[$user->id] ?? 0),
                'hours_this_week' => round($hoursThisWeek, 1),
                'capacity_pct'    => $capacityPct,
            ];
        });

        return $this->success([
            'heatmap' => $values,
            'members' => $memberData,
        ]);
    }
}
