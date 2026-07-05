<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\RejectApprovalRequest;
use App\Http\Requests\Approval\StoreApprovalRequest;
use App\Http\Resources\ApprovalResource;
use App\Http\Traits\ApiResponse;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects/{project}/approvals
    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        $query = $project->approvals()
            ->with(['deliverable:id,original_name,file_path,version', 'requestedBy:id,name', 'client:id,company_name']);

        // Client role: filter to their own approvals only
        if ($user->role === 'client') {
            $client = Client::withoutAgencyScope()
                ->where('agency_id', $project->agency_id)
                ->where('contact_user_id', $user->id)
                ->first();

            if (!$client || $project->client_id !== $client->id) {
                return $this->forbidden('You do not have access to this project.');
            }

            $query->where('client_id', $client->id);
        }

        $approvals = $query->orderByDesc('created_at')->get();

        return $this->success(ApprovalResource::collection($approvals));
    }

    // POST /api/v1/projects/{project}/approvals — PM requests client approval
    public function store(StoreApprovalRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // The client linked to this project
        $client = $project->client;
        if (!$client) {
            return $this->error('This project has no linked client.', [], 422);
        }

        $approval = Approval::create([
            'agency_id'       => $project->agency_id,
            'project_id'      => $project->id,
            'deliverable_id'  => $request->deliverable_id,
            'client_id'       => $client->id,
            'requested_by_id' => $user->id,
            'status'          => 'pending',
            'requested_at'    => now(),
        ]);

        ProjectEvent::log($user->agency_id, $project->id, 'approval_requested', [
            'approval_id'    => $approval->id,
            'deliverable_id' => $request->deliverable_id,
            'requested_by'   => $user->id,
            'client_id'      => $client->id,
            'notes'          => $request->notes,
        ]);

        $approval->load(['deliverable:id,original_name,file_path,version', 'requestedBy:id,name', 'client:id,company_name']);

        return $this->created(new ApprovalResource($approval), 'Approval request sent to client.');
    }

    // PATCH /api/v1/projects/{project}/approvals/{approval}/approve — client approves
    public function approve(Request $request, Project $project, Approval $approval): JsonResponse
    {
        if ($approval->project_id !== $project->id) {
            return $this->notFound('Approval not found on this project.');
        }

        if ($approval->status !== 'pending') {
            return $this->error('This approval has already been responded to.', [], 422);
        }

        $user   = $request->user();
        $client = $this->resolveClientForUser($user, $project);

        if (!$client || $approval->client_id !== $client->id) {
            return $this->forbidden('You do not have permission to respond to this approval.');
        }

        $respondedAt     = now();
        $lagHours        = (int) ceil($approval->requested_at->diffInHours($respondedAt));

        $approval->update([
            'status'              => 'approved',
            'responded_at'        => $respondedAt,
            'approval_lag_hours'  => $lagHours,
        ]);

        ProjectEvent::log($project->agency_id, $project->id, 'approval_given', [
            'approval_id'         => $approval->id,
            'approval_lag_hours'  => $lagHours,
            'approved_by'         => $user->id,
            'client_id'           => $client->id,
        ]);

        $approval->load(['deliverable:id,original_name,file_path,version', 'requestedBy:id,name', 'client:id,company_name']);

        return $this->success(new ApprovalResource($approval), 'Deliverable approved.');
    }

    // PATCH /api/v1/projects/{project}/approvals/{approval}/reject — client rejects
    public function reject(RejectApprovalRequest $request, Project $project, Approval $approval): JsonResponse
    {
        if ($approval->project_id !== $project->id) {
            return $this->notFound('Approval not found on this project.');
        }

        if ($approval->status !== 'pending') {
            return $this->error('This approval has already been responded to.', [], 422);
        }

        $user   = $request->user();
        $client = $this->resolveClientForUser($user, $project);

        if (!$client || $approval->client_id !== $client->id) {
            return $this->forbidden('You do not have permission to respond to this approval.');
        }

        $respondedAt = now();
        $lagHours    = (int) ceil($approval->requested_at->diffInHours($respondedAt));

        $approval->update([
            'status'              => 'rejected',
            'responded_at'        => $respondedAt,
            'approval_lag_hours'  => $lagHours,
        ]);

        ProjectEvent::log($project->agency_id, $project->id, 'approval_rejected', [
            'approval_id'        => $approval->id,
            'reason'             => $request->reason,
            'approval_lag_hours' => $lagHours,
            'rejected_by'        => $user->id,
            'client_id'          => $client->id,
        ]);

        $approval->load(['deliverable:id,original_name,file_path,version', 'requestedBy:id,name', 'client:id,company_name']);

        return $this->success(new ApprovalResource($approval), 'Deliverable rejected.');
    }

    private function resolveClientForUser($user, Project $project): ?Client
    {
        return Client::withoutAgencyScope()
            ->where('agency_id', $project->agency_id)
            ->where('contact_user_id', $user->id)
            ->first();
    }
}
