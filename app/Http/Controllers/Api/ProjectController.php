<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectCollection;
use App\Http\Resources\ProjectResource;
use App\Http\Traits\ApiResponse;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // HasAgencyScope automatically scopes to auth user's agency_id.
        // withCount uses conditional subqueries to avoid N+1 for progress calculation.
        $projects = Project::query()
            ->visibleTo($user)
            ->with([
                'client:id,company_name,contact_user_id',
                'client.contactUser:id,name,email,avatar_path',
                'latestHealthScore',
                'milestones' => fn ($q) => $q->orderBy('due_date'),
                'tasks' => fn ($q) => $q->whereNotNull('assignee_id')->with('assignee:id,name,role,avatar_path'),
                'teamMembers:id,name,role,avatar_path',
            ])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn($q) => $q->where('status', 'done'),
                'tasks as in_progress_tasks_count' => fn($q) => $q->where('status', 'in_progress'),
                'milestones',
            ])
            ->orderByDesc('created_at')
            ->get();

        return $this->success(new ProjectCollection($projects));
    }

    // POST /api/v1/projects
    public function store(StoreProjectRequest $request): JsonResponse
    {
        $user = $request->user();

        $project = DB::transaction(function () use ($request, $user) {
            $startDate = $request->start_date
                ? \Carbon\Carbon::parse($request->start_date)
                : now()->startOfDay();

            $project = Project::create([
                'agency_id'       => $user->agency_id,
                'client_id'       => $request->client_id,
                'name'            => $request->name,
                'type'            => $request->type,
                'description'     => $request->description,
                'status'          => $request->input('status', 'started'),
                'priority'        => $request->input('priority', 'medium'),
                'budget'          => $request->budget,
                'estimated_hours' => $request->estimated_hours,
                'start_date'      => $startDate->toDateString(),
                'end_date'        => $request->end_date,
                'color'           => $request->color ?? '#802AEE',
            ]);

            $teamMemberIds = collect($request->input('team_member_ids', []))
                ->filter()
                ->unique()
                ->values();

            if ($teamMemberIds->isNotEmpty()) {
                $project->teamMembers()->sync($teamMemberIds);
            }

            $submittedMilestones = collect($request->input('milestones', []))
                ->filter(fn ($m) => !empty($m['title'] ?? null));

            if ($submittedMilestones->isNotEmpty()) {
                foreach ($submittedMilestones as $m) {
                    $dueDate = isset($m['due_offset_days'])
                        ? $startDate->copy()->addDays((int) $m['due_offset_days'])
                        : $startDate->copy();

                    $milestone = Milestone::create([
                        'agency_id'  => $user->agency_id,
                        'project_id' => $project->id,
                        'title'      => $m['title'],
                        'due_date'   => $dueDate->toDateString(),
                        'status'     => 'pending',
                    ]);

                    $tasks = collect($m['tasks'] ?? [])
                        ->filter(fn ($t) => !empty($t['title'] ?? null))
                        ->take(8);

                    foreach ($tasks as $t) {
                        Task::create([
                            'agency_id'       => $user->agency_id,
                            'project_id'      => $project->id,
                            'milestone_id'    => $milestone->id,
                            'title'           => $t['title'],
                            'status'          => 'todo',
                            'priority'        => in_array($t['priority'] ?? 'medium', ['low', 'medium', 'high'], true)
                                ? $t['priority']
                                : 'medium',
                            'estimated_hours' => isset($t['estimated_hours']) ? (int) $t['estimated_hours'] : null,
                        ]);
                    }
                }
            } else {
                Milestone::create([
                    'agency_id'  => $user->agency_id,
                    'project_id' => $project->id,
                    'title'      => 'Project Kickoff',
                    'due_date'   => $startDate->toDateString(),
                    'status'     => 'pending',
                ]);
            }

            ProjectEvent::log($user->agency_id, $project->id, 'project_created', [
                'project_name' => $project->name,
                'client_id'    => $project->client_id,
                'type'         => $project->type,
                'status'       => $project->status,
                'priority'     => $project->priority,
                'budget'       => $project->budget,
                'created_by'   => $user->id,
            ]);

            return $project;
        });

        $project->load(['client:id,company_name,contact_user_id', 'client.contactUser:id,name,email,avatar_path', 'milestones.tasks', 'latestHealthScore', 'teamMembers:id,name,role,avatar_path']);

        return $this->created(new ProjectResource($project), 'Project created successfully.');
    }

    // GET /api/v1/projects/{project}
    public function show(Request $request, Project $project): JsonResponse
    {
        if (!$project->isVisibleTo($request->user())) {
            return $this->notFound('Project not found.');
        }

        // Route model binding resolves through HasAgencyScope — 404 if wrong agency.
        $project->load([
            'client:id,company_name,contact_user_id',
            'client.contactUser:id,name,email,avatar_path',
            'latestHealthScore',
            'milestones.tasks',
            'tasks.assignee:id,name,role,avatar_path',
            'teamMembers:id,name,role,avatar_path',
        ]);

        return $this->success(new ProjectResource($project));
    }

    // PUT /api/v1/projects/{project}
    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $validated = $request->validated();
        $teamMemberIds = array_key_exists('team_member_ids', $validated)
            ? collect($validated['team_member_ids'] ?? [])->filter()->unique()->values()->all()
            : null;
        unset($validated['team_member_ids']);

        $changed = array_keys($validated);
        if ($teamMemberIds !== null) {
            $changed[] = 'team_member_ids';
        }

        $project->update($validated);

        if ($teamMemberIds !== null) {
            $project->teamMembers()->sync($teamMemberIds);
        }

        ProjectEvent::log($request->user()->agency_id, $project->id, 'project_updated', [
            'changed_fields' => $changed,
            'updated_by'     => $request->user()->id,
        ]);

        $project->load([
            'client:id,company_name,contact_user_id',
            'client.contactUser:id,name,email,avatar_path',
            'latestHealthScore',
            'teamMembers:id,name,role,avatar_path',
        ]);

        return $this->success(new ProjectResource($project), 'Project updated successfully.');
    }

    // DELETE /api/v1/projects/{project}  — soft delete only, never hard delete
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $name = $project->name;

        $project->delete(); // SoftDeletes — sets deleted_at, never removes the row

        ProjectEvent::log($request->user()->agency_id, $project->id, 'project_archived', [
            'project_name' => $name,
            'archived_by'  => $request->user()->id,
        ]);

        return $this->success(message: "Project \"{$name}\" archived successfully.");
    }
}
