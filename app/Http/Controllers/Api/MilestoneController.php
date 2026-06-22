<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Milestone\StoreMilestoneRequest;
use App\Http\Requests\Milestone\UpdateMilestoneRequest;
use App\Http\Resources\MilestoneResource;
use App\Http\Traits\ApiResponse;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects/{project}/milestones
    public function index(Project $project): JsonResponse
    {
        // $project already resolved via HasAgencyScope route model binding.
        $milestones = $project->milestones()
            ->withCount('tasks')
            ->withCount(['tasks as done_tasks_count' => fn($q) => $q->where('status', 'done')])
            ->orderBy('due_date')
            ->get();

        return $this->success(MilestoneResource::collection($milestones));
    }

    // POST /api/v1/projects/{project}/milestones
    public function store(StoreMilestoneRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();

        $milestone = Milestone::create([
            'agency_id'  => $user->agency_id,
            'project_id' => $project->id,
            'title'      => $request->title,
            'due_date'   => $request->due_date,
            'status'     => 'pending',
        ]);

        ProjectEvent::log($user->agency_id, $project->id, 'milestone_created', [
            'milestone_title' => $milestone->title,
            'due_date'        => $milestone->due_date?->toDateString(),
            'created_by'      => $user->id,
        ]);

        return $this->created(new MilestoneResource($milestone), 'Milestone created successfully.');
    }

    // PUT /api/v1/projects/{project}/milestones/{milestone}
    public function update(UpdateMilestoneRequest $request, Project $project, Milestone $milestone): JsonResponse
    {
        // Verify milestone belongs to this project (defence-in-depth on top of agency scope)
        if ($milestone->project_id !== $project->id) {
            return $this->notFound('Milestone not found on this project.');
        }

        $milestone->update($request->validated());

        return $this->success(new MilestoneResource($milestone), 'Milestone updated successfully.');
    }

    // PATCH /api/v1/projects/{project}/milestones/{milestone}/complete
    public function complete(Request $request, Project $project, Milestone $milestone): JsonResponse
    {
        if ($milestone->project_id !== $project->id) {
            return $this->notFound('Milestone not found on this project.');
        }

        if ($milestone->status === 'completed') {
            return $this->error('Milestone is already completed.', [], 422);
        }

        $milestone->update(['status' => 'completed']);

        ProjectEvent::log($request->user()->agency_id, $project->id, 'milestone_completed', [
            'milestone_title' => $milestone->title,
            'completed_at'    => now()->toIso8601String(),
            'completed_by'    => $request->user()->id,
        ]);

        return $this->success(new MilestoneResource($milestone), 'Milestone marked as completed.');
    }
}
