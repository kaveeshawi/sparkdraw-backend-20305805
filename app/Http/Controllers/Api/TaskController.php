<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateStatusRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskCollection;
use App\Http\Resources\TaskResource;
use App\Http\Traits\ApiResponse;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use ApiResponse;

    // GET /api/v1/tasks[?status=&mine_only=1] — agency-wide, across all projects (the "My Tasks" nav page)
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Task::with(['assignee:id,name,role', 'project:id,name,color'])
            ->withCount('timeLogs');

        if ($request->boolean('mine_only', true)) {
            $query->where('assignee_id', $user->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $tasks = $query->orderByRaw('deadline IS NULL, deadline')->orderByDesc('created_at')->get();

        return $this->success(TaskResource::collection($tasks));
    }

    // GET /api/v1/projects/{project}/tasks[?status=&assignee_id=&priority=&group_by=status]
    public function index(Request $request, Project $project): JsonResponse
    {
        $query = $project->tasks()
            ->with('assignee:id,name,role')
            ->withCount('timeLogs');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('assignee_id')) {
            $query->where('assignee_id', $request->assignee_id);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        $tasks   = $query->orderBy('created_at')->get();
        $grouped = $request->query('group_by') === 'status';

        return $this->success(new TaskCollection($tasks, $grouped));
    }

    // POST /api/v1/projects/{project}/tasks
    public function store(StoreTaskRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Verify assignee belongs to same agency if provided
        if ($request->assignee_id) {
            $assigneeExists = User::withoutAgencyScope()
                ->where('id', $request->assignee_id)
                ->where('agency_id', $user->agency_id)
                ->exists();

            if (!$assigneeExists) {
                return $this->error('Assignee does not belong to this agency.', [], 422);
            }
        }

        $task = Task::create([
            'agency_id'       => $user->agency_id,
            'project_id'      => $project->id,
            'milestone_id'    => $request->milestone_id,
            'assignee_id'     => $request->assignee_id,
            'title'           => $request->title,
            'description'     => $request->description,
            'status'          => 'todo',
            'priority'        => $request->priority ?? 'medium',
            'estimated_hours' => $request->estimated_hours,
            'deadline'        => $request->deadline,
        ]);

        ProjectEvent::log($user->agency_id, $project->id, 'task_created', [
            'task_title'      => $task->title,
            'priority'        => $task->priority,
            'estimated_hours' => $task->estimated_hours,
            'milestone_id'    => $task->milestone_id,
            'created_by'      => $user->id,
        ]);

        $task->load('assignee:id,name,role');

        return $this->created(new TaskResource($task), 'Task created successfully.');
    }

    // GET /api/v1/projects/{project}/tasks/{task}
    public function show(Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $task->load('assignee:id,name,role', 'milestone:id,title,status');
        $task->loadCount('timeLogs');

        $totalHoursLogged = $task->timeLogs()->sum('hours');

        return $this->success([
            'task'               => new TaskResource($task),
            'total_hours_logged' => (float) $totalHoursLogged,
        ]);
    }

    // PUT /api/v1/projects/{project}/tasks/{task}
    public function update(UpdateTaskRequest $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $changed = array_keys($request->validated());
        $task->update($request->validated());

        ProjectEvent::log($request->user()->agency_id, $project->id, 'task_updated', [
            'task_title'     => $task->title,
            'changed_fields' => $changed,
            'updated_by'     => $request->user()->id,
        ]);

        $task->load('assignee:id,name,role');

        return $this->success(new TaskResource($task), 'Task updated successfully.');
    }

    // PATCH /api/v1/projects/{project}/tasks/{task}/status
    public function updateStatus(UpdateStatusRequest $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $fromStatus = $task->status;
        $toStatus   = $request->status;

        if ($fromStatus === $toStatus) {
            return $this->error('Task is already in that status.', [], 422);
        }

        $task->update(['status' => $toStatus]);

        $user = $request->user();

        ProjectEvent::log($user->agency_id, $project->id, 'task_status_changed', [
            'task_title'  => $task->title,
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
            'changed_by'  => $user->id,
        ]);

        // When a task is completed, log task_completed event
        if ($toStatus === 'done') {
            ProjectEvent::log($user->agency_id, $project->id, 'task_completed', [
                'task_title'      => $task->title,
                'actual_hours'    => $task->actual_hours,
                'estimated_hours' => $task->estimated_hours,
            ]);

            // Auto-complete the milestone if this was its last pending task
            if ($task->milestone_id) {
                $this->autoCompleteMilestoneIfAllDone($user->agency_id, $project->id, $task->milestone_id);
            }
        }

        return $this->success(new TaskResource($task), 'Task status updated.');
    }

    // DELETE /api/v1/projects/{project}/tasks/{task}  — soft delete only
    public function destroy(Request $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $title = $task->title;
        $task->delete(); // SoftDeletes

        ProjectEvent::log($request->user()->agency_id, $project->id, 'task_deleted', [
            'task_title' => $title,
            'deleted_by' => $request->user()->id,
        ]);

        return $this->success(message: "Task \"{$title}\" deleted.");
    }

    // PATCH /api/v1/projects/{project}/tasks/{task}/assign
    public function assign(Request $request, Project $project, Task $task): JsonResponse
    {
        if ($task->project_id !== $project->id) {
            return $this->notFound('Task not found on this project.');
        }

        $request->validate([
            'assignee_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        // Assignee must belong to same agency
        $assignee = User::withoutAgencyScope()
            ->where('id', $request->assignee_id)
            ->where('agency_id', $user->agency_id)
            ->first();

        if (!$assignee) {
            return $this->error('Assignee does not belong to this agency.', [], 422);
        }

        $task->update(['assignee_id' => $assignee->id]);
        $task->load('assignee:id,name,role');

        ProjectEvent::log($user->agency_id, $project->id, 'task_assigned', [
            'task_title'    => $task->title,
            'assignee_name' => $assignee->name,
            'assigned_by'   => $user->id,
        ]);

        return $this->success(new TaskResource($task), "Task assigned to {$assignee->name}.");
    }

    // Checks if all tasks in the milestone are done; if so, marks milestone completed.
    private function autoCompleteMilestoneIfAllDone(int $agencyId, int $projectId, int $milestoneId): void
    {
        // Load via scoped relationship to prevent cross-agency access
        $milestone = \App\Models\Milestone::withoutAgencyScope()
            ->where('id', $milestoneId)
            ->where('agency_id', $agencyId)
            ->first();

        if (!$milestone || $milestone->status === 'completed') {
            return;
        }

        // Check using tasks table directly — withoutAgencyScope since we already validated agency
        $totalTasks      = Task::withoutGlobalScope('agency')->where('milestone_id', $milestoneId)->whereNull('deleted_at')->count();
        $completedTasks  = Task::withoutGlobalScope('agency')->where('milestone_id', $milestoneId)->where('status', 'done')->whereNull('deleted_at')->count();

        if ($totalTasks > 0 && $totalTasks === $completedTasks) {
            $milestone->update(['status' => 'completed']);

            ProjectEvent::log($agencyId, $projectId, 'milestone_completed', [
                'milestone_title' => $milestone->title,
                'total_tasks'     => $totalTasks,
                'completed_at'    => now()->toIso8601String(),
                'auto_completed'  => true,
            ]);
        }
    }
}
