<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Revision\StoreRevisionRequest;
use App\Http\Requests\Revision\UpdateRevisionRequest;
use App\Http\Resources\RevisionResource;
use App\Http\Traits\ApiResponse;
use App\Jobs\AIFeedbackJob;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Revision;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RevisionController extends Controller
{
    use ApiResponse;

    // GET /api/v1/revisions/with-tickets — agency-wide, feeds the dashboard AI feedback panel
    public function withTickets(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 3);

        $revisions = Revision::whereNotNull('ai_ticket_json')
            ->with(['client:id,company_name', 'submittedBy:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success(RevisionResource::collection($revisions));
    }

    // GET /api/v1/revisions[?status=] — agency-wide feed across all projects (the Revisions nav page)
    public function all(Request $request): JsonResponse
    {
        $query = Revision::with(['client:id,company_name', 'submittedBy:id,name', 'project:id,name,color'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $revisions = $query->get();

        return $this->success(RevisionResource::collection($revisions));
    }

    // GET /api/v1/projects/{project}/revisions
    public function index(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        $query = $project->revisions()
            ->with(['client:id,company_name', 'submittedBy:id,name']);

        // Client role: only their own project's feedback — verify ownership
        if ($user->role === 'client') {
            $client = Client::withoutAgencyScope()
                ->where('agency_id', $project->agency_id)
                ->where('contact_user_id', $user->id)
                ->first();

            if (!$client || $project->client_id !== $client->id) {
                return $this->forbidden('You do not have access to this project.');
            }
        }

        $revisions = $query->orderByDesc('round_number')->get();

        return $this->success(RevisionResource::collection($revisions));
    }

    // POST /api/v1/projects/{project}/revisions
    public function store(StoreRevisionRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Resolve the client record linked to the submitting user
        $client = Client::withoutAgencyScope()
            ->where('agency_id', $project->agency_id)
            ->where('contact_user_id', $user->id)
            ->first();

        if (!$client) {
            return $this->forbidden('No client profile found for your user account on this agency.');
        }

        // Verify client belongs to this project
        if ($project->client_id !== $client->id) {
            return $this->forbidden('You do not have access to this project.');
        }

        $revision = DB::transaction(function () use ($request, $project, $user, $client) {
            // Auto-calculate round_number — never trust input
            $roundNumber = $project->revisions()->count() + 1;

            $revision = Revision::create([
                'agency_id'     => $project->agency_id,
                'project_id'    => $project->id,
                'client_id'     => $client->id,
                'submitted_by_id' => $user->id,
                'round_number'  => $roundNumber,
                'feedback_text' => $request->feedback_text,
                'status'        => 'pending',
            ]);

            ProjectEvent::log($project->agency_id, $project->id, 'revision_created', [
                'round_number'    => $roundNumber,
                'feedback_length' => strlen($request->feedback_text),
                'submitted_by'    => $user->id,
            ]);

            // A5: Revision risk detection — 3+ revisions = scope creep warning (no AI needed)
            if ($roundNumber >= 3) {
                ProjectEvent::log($project->agency_id, $project->id, 'revision_risk_detected', [
                    'round_number' => $roundNumber,
                    'project_id'   => $project->id,
                    'message'      => "Project is on revision round {$roundNumber} — consider scope change discussion",
                ]);
            }

            return $revision;
        });

        // Dispatch AI analysis on dedicated 'ai' queue — never on default
        AIFeedbackJob::dispatch($revision)->onQueue('ai');

        ProjectEvent::log($project->agency_id, $project->id, 'ai_feedback_job_dispatched', [
            'revision_id'  => $revision->id,
            'round_number' => $revision->round_number,
        ]);

        $revision->load(['client:id,company_name', 'submittedBy:id,name']);

        return $this->created(new RevisionResource($revision), 'Feedback submitted successfully. AI analysis queued.');
    }

    // PUT /api/v1/projects/{project}/revisions/{revision}
    public function update(UpdateRevisionRequest $request, Project $project, Revision $revision): JsonResponse
    {
        if ($revision->project_id !== $project->id) {
            return $this->notFound('Revision not found on this project.');
        }

        $oldStatus = $revision->status;
        $newStatus = $request->status;

        $revision->update(['status' => $newStatus]);

        $eventType = match ($newStatus) {
            'acknowledged' => 'revision_acknowledged',
            'resolved'     => 'revision_resolved',
            default        => 'revision_updated',
        };

        ProjectEvent::log($request->user()->agency_id, $project->id, $eventType, [
            'revision_id'  => $revision->id,
            'round_number' => $revision->round_number,
            'from_status'  => $oldStatus,
            'to_status'    => $newStatus,
            'updated_by'   => $request->user()->id,
        ]);

        $revision->load(['client:id,company_name', 'submittedBy:id,name']);

        return $this->success(new RevisionResource($revision), 'Revision status updated.');
    }

    // POST /api/v1/projects/{project}/revisions/{revision}/accept-ticket
    public function acceptTicket(Request $request, Project $project, Revision $revision): JsonResponse
    {
        if ($revision->project_id !== $project->id) {
            return $this->notFound('Revision not found on this project.');
        }

        if (!$revision->ai_ticket_json) {
            return $this->error('This revision has no AI-generated ticket to accept.', [], 422);
        }

        $ticket = $revision->ai_ticket_json;
        $subtasks = $ticket['subtasks'] ?? [];

        if (empty($subtasks)) {
            return $this->error('AI ticket contains no subtasks to create.', [], 422);
        }

        $user = $request->user();
        $createdTaskIds = [];

        DB::transaction(function () use ($project, $revision, $ticket, $subtasks, $user, &$createdTaskIds) {
            foreach ($subtasks as $subtaskTitle) {
                $task = Task::create([
                    'agency_id'  => $project->agency_id,
                    'project_id' => $project->id,
                    'title'      => is_string($subtaskTitle) ? $subtaskTitle : ($subtaskTitle['title'] ?? 'AI Generated Task'),
                    'status'     => 'todo',
                    'priority'   => $ticket['priority'] ?? 'medium',
                ]);
                $createdTaskIds[] = $task->id;
            }

            ProjectEvent::log($user->agency_id, $project->id, 'ai_ticket_accepted', [
                'revision_id'      => $revision->id,
                'round_number'     => $revision->round_number,
                'tasks_created'    => count($createdTaskIds),
                'ticket_title'     => $ticket['title'] ?? null,
                'accepted_by'      => $user->id,
            ]);
        });

        return $this->success([
            'created_task_ids' => $createdTaskIds,
            'tasks_count'      => count($createdTaskIds),
        ], 'AI ticket accepted. ' . count($createdTaskIds) . ' tasks created.');
    }
}
