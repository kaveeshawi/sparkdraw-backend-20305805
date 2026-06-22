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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects
    public function index(Request $request): JsonResponse
    {
        // HasAgencyScope automatically scopes to auth user's agency_id.
        // withCount uses conditional subqueries to avoid N+1 for progress calculation.
        $projects = Project::with([
                'client:id,company_name',
                'latestHealthScore',
                'milestones' => fn ($q) => $q->orderBy('due_date'),
                'tasks' => fn ($q) => $q->whereNotNull('assignee_id')->with('assignee:id,name,role,avatar_path'),
                'teamMembers:id,name,role,avatar_path',
            ])
            ->withCount([
                'tasks',
                'tasks as done_tasks_count' => fn($q) => $q->where('status', 'done'),
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
                'start_date'      => $request->start_date,
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
                $startDate = \Carbon\Carbon::parse($request->start_date);

                foreach ($submittedMilestones as $m) {
                    $dueDate = isset($m['due_offset_days'])
                        ? $startDate->copy()->addDays((int) $m['due_offset_days'])
                        : $request->start_date;

                    Milestone::create([
                        'agency_id'  => $user->agency_id,
                        'project_id' => $project->id,
                        'title'      => $m['title'],
                        'due_date'   => $dueDate,
                        'status'     => 'pending',
                    ]);
                }
            } else {
                // Auto-generate welcome milestone so the project has structure from day one
                Milestone::create([
                    'agency_id'  => $user->agency_id,
                    'project_id' => $project->id,
                    'title'      => 'Project Kickoff',
                    'due_date'   => $request->start_date,
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

        $project->load(['client:id,company_name', 'milestones', 'latestHealthScore', 'teamMembers:id,name,role,avatar_path']);

        return $this->created(new ProjectResource($project), 'Project created successfully.');
    }

    // GET /api/v1/projects/{project}
    public function show(Project $project): JsonResponse
    {
        // Route model binding resolves through HasAgencyScope — 404 if wrong agency.
        $project->load([
            'client:id,company_name',
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
        $changed = array_keys($request->validated());

        $project->update($request->validated());

        ProjectEvent::log($request->user()->agency_id, $project->id, 'project_updated', [
            'changed_fields' => $changed,
            'updated_by'     => $request->user()->id,
        ]);

        $project->load(['client:id,company_name', 'latestHealthScore']);

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
