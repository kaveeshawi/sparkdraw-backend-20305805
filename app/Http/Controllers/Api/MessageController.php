<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Jobs\SentimentJob;
use App\Models\Client;
use App\Models\Message;
use App\Models\MessageThreadRead;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MessageController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects/{project}/messages
    // ?thread=overall (default) — project-wide messages (recipient_id null)
    // ?with={userId} — 1:1 DM between auth user and that user
    public function index(Request $request, Project $project): JsonResponse
    {
        if (!$this->canAccessProject($request, $project)) {
            return $this->forbidden('You do not have access to this project.');
        }

        $withUserId = $request->filled('with') ? (int) $request->query('with') : null;

        $query = $project->messages()->with([
            'sender:id,name,role',
            'recipient:id,name,role',
        ]);

        if ($withUserId) {
            $peer = User::where('agency_id', $project->agency_id)->find($withUserId);
            if (!$peer || !$this->canDmPeer($request->user(), $project, $peer)) {
                return $this->forbidden('You cannot view this direct thread.');
            }

            $me = $request->user()->id;
            $query->where(function ($q) use ($me, $withUserId) {
                $q->where(function ($inner) use ($me, $withUserId) {
                    $inner->where('sender_id', $me)->where('recipient_id', $withUserId);
                })->orWhere(function ($inner) use ($me, $withUserId) {
                    $inner->where('sender_id', $withUserId)->where('recipient_id', $me);
                });
            });
        } else {
            $query->whereNull('recipient_id');
        }

        $messages = $query
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Message $m) => $this->messagePayload($m));

        return $this->success($messages);
    }

    // POST /api/v1/projects/{project}/messages
    public function store(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'body'         => ['required', 'string', 'min:1', 'max:5000'],
            'recipient_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (!$this->canAccessProject($request, $project)) {
            return $this->forbidden('You do not have access to this project.');
        }

        $user = $request->user();
        $recipientId = $request->filled('recipient_id') ? (int) $request->input('recipient_id') : null;

        if ($recipientId) {
            $peer = User::where('agency_id', $project->agency_id)->find($recipientId);
            if (!$peer || !$this->canDmPeer($user, $project, $peer)) {
                return $this->forbidden('You cannot message that user on this project.');
            }
            if ($peer->id === $user->id) {
                return $this->error('Cannot message yourself.', [], 422);
            }
        }

        $message = Message::create([
            'agency_id'    => $project->agency_id,
            'project_id'   => $project->id,
            'sender_id'    => $user->id,
            'recipient_id' => $recipientId,
            'body'         => $request->body,
        ]);

        ProjectEvent::log($project->agency_id, $project->id, 'message_sent', [
            'message_id'   => $message->id,
            'sender_id'    => $user->id,
            'sender_role'  => $user->role,
            'recipient_id' => $recipientId,
            'thread'       => $recipientId ? 'direct' : 'overall',
        ]);

        SentimentJob::dispatch($message)->onQueue('ai');

        $message->load(['sender:id,name,role', 'recipient:id,name,role']);

        return $this->created(
            $this->messagePayload($message),
            'Message sent. Sentiment analysis queued.'
        );
    }

    // POST /api/v1/projects/{project}/messages/read
    // Body: { with?: userId } — omit / null = overall thread
    public function markRead(Request $request, Project $project): JsonResponse
    {
        if (!$this->canAccessProject($request, $project)) {
            return $this->forbidden('You do not have access to this project.');
        }

        $request->validate([
            'with' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $peerUserId = $request->filled('with') ? (int) $request->input('with') : 0;

        if ($peerUserId > 0) {
            $peer = User::where('agency_id', $project->agency_id)->find($peerUserId);
            if (!$peer || !$this->canDmPeer($user, $project, $peer)) {
                return $this->forbidden('You cannot mark this direct thread as read.');
            }
        }

        $read = MessageThreadRead::withoutGlobalScope('agency')->updateOrCreate(
            [
                'user_id'      => $user->id,
                'project_id'   => $project->id,
                'peer_user_id' => $peerUserId,
            ],
            [
                'agency_id'    => $project->agency_id,
                'last_read_at' => now(),
            ]
        );

        return $this->success([
            'project_id'   => $project->id,
            'peer_user_id' => $peerUserId,
            'last_read_at' => $read->last_read_at?->toIso8601String(),
        ], 'Thread marked as read.');
    }

    // GET /api/v1/messages/unread
    public function unreadSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $projects = $this->accessibleProjects($user);

        if ($projects->isEmpty()) {
            return $this->success([
                'total'    => 0,
                'clients'  => [],
                'threads'  => [],
            ]);
        }

        $projectIds = $projects->pluck('id')->all();
        $projectById = $projects->keyBy('id');

        $reads = MessageThreadRead::withoutGlobalScope('agency')
            ->where('user_id', $user->id)
            ->whereIn('project_id', $projectIds)
            ->get()
            ->keyBy(fn (MessageThreadRead $r) => $r->project_id.':'.$r->peer_user_id);

        $messages = Message::withoutGlobalScope('agency')
            ->where('agency_id', $user->agency_id)
            ->whereIn('project_id', $projectIds)
            ->where('sender_id', '!=', $user->id)
            ->orderByDesc('id')
            ->limit(1500)
            ->get(['id', 'project_id', 'sender_id', 'recipient_id', 'created_at']);

        $threadCounts = [];
        $clientCounts = [];

        foreach ($messages as $message) {
            $peer = $this->threadPeerForViewer($message, (int) $user->id);
            if ($peer === null) {
                continue;
            }

            $key = $message->project_id.':'.$peer;
            $lastRead = $reads->get($key)?->last_read_at;

            if ($lastRead instanceof Carbon) {
                if ($message->created_at <= $lastRead) {
                    continue;
                }
            } else {
                // Never opened this thread — ignore historic backlog so the nav badge
                // stays quiet unless something arrived recently.
                if ($message->created_at->lt(now()->subDay())) {
                    continue;
                }
            }

            $threadCounts[$key] = ($threadCounts[$key] ?? 0) + 1;

            $project = $projectById->get($message->project_id);
            if ($project && $project->client_id) {
                $clientCounts[$project->client_id] = ($clientCounts[$project->client_id] ?? 0) + 1;
            }
        }

        $neededClientIds = collect(array_keys($clientCounts))
            ->merge($projects->pluck('client_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Always surface clients the user has a Direct thread with (even if unread is 0),
        // so members can reopen portal DMs after marking them read.
        $dmClientIds = Message::withoutGlobalScope('agency')
            ->where('agency_id', $user->agency_id)
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('recipient_id')
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                    ->orWhere('recipient_id', $user->id);
            })
            ->pluck('project_id')
            ->unique()
            ->map(fn ($pid) => $projectById->get($pid)?->client_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $neededClientIds = collect($neededClientIds)
            ->merge($dmClientIds)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $clientRows = $neededClientIds
            ? Client::withoutAgencyScope()
                ->whereIn('id', $neededClientIds)
                ->get(['id', 'company_name', 'contact_user_id'])
                ->keyBy('id')
            : collect();

        $threads = [];
        foreach ($threadCounts as $key => $count) {
            [$projectId, $peerUserId] = array_map('intval', explode(':', $key, 2));
            $project = $projectById->get($projectId);
            $client = $project?->client_id ? $clientRows->get($project->client_id) : null;
            $threads[] = [
                'project_id'      => $projectId,
                'client_id'       => $project?->client_id,
                'client_name'     => $client?->company_name,
                'contact_user_id' => $client?->contact_user_id,
                'peer_user_id'    => $peerUserId,
                'unread'          => $count,
            ];
        }

        // Seed zero-unread Direct threads so the UI can list them after mark-read.
        $existingKeys = [];
        foreach ($threads as $row) {
            $existingKeys[$row['project_id'].':'.$row['peer_user_id']] = true;
        }

        $latestDms = Message::withoutGlobalScope('agency')
            ->where('agency_id', $user->agency_id)
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('recipient_id')
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                    ->orWhere('recipient_id', $user->id);
            })
            ->orderByDesc('id')
            ->get(['project_id', 'sender_id', 'recipient_id']);

        $seenDmProjects = [];
        foreach ($latestDms as $dm) {
            $pid = (int) $dm->project_id;
            if (isset($seenDmProjects[$pid])) {
                continue;
            }
            $seenDmProjects[$pid] = true;

            $peerUserId = (int) $dm->sender_id === (int) $user->id
                ? (int) $dm->recipient_id
                : (int) $dm->sender_id;
            $key = $pid.':'.$peerUserId;
            if (isset($existingKeys[$key])) {
                continue;
            }

            $project = $projectById->get($pid);
            $client = $project?->client_id ? $clientRows->get($project->client_id) : null;
            $threads[] = [
                'project_id'      => $pid,
                'client_id'       => $project?->client_id,
                'client_name'     => $client?->company_name,
                'contact_user_id' => $client?->contact_user_id,
                'peer_user_id'    => $peerUserId,
                'unread'          => 0,
            ];
            $existingKeys[$key] = true;
        }

        $clients = [];
        $clientIdSet = collect(array_keys($clientCounts))->merge($dmClientIds)->unique();
        foreach ($clientIdSet as $clientId) {
            $client = $clientRows->get($clientId);
            $clients[] = [
                'client_id'       => (int) $clientId,
                'company_name'    => $client?->company_name,
                'contact_user_id' => $client?->contact_user_id,
                'unread'          => (int) ($clientCounts[$clientId] ?? 0),
            ];
        }

        usort($threads, fn ($a, $b) => $b['unread'] <=> $a['unread']);
        usort($clients, fn ($a, $b) => $b['unread'] <=> $a['unread']);

        return $this->success([
            'total'   => array_sum($threadCounts),
            'clients' => $clients,
            'threads' => $threads,
        ]);
    }

    private function accessibleProjects(User $user): Collection
    {
        if (in_array($user->role, ['admin', 'pm'], true)) {
            return Project::withoutGlobalScope('agency')
                ->where('agency_id', $user->agency_id)
                ->whereNull('deleted_at')
                ->get(['id', 'client_id', 'agency_id']);
        }

        if ($user->role === 'member') {
            $visibleIds = Project::query()
                ->visibleTo($user)
                ->pluck('id');

            $dmProjectIds = Message::withoutGlobalScope('agency')
                ->where('agency_id', $user->agency_id)
                ->where(function ($q) use ($user) {
                    $q->where('sender_id', $user->id)
                        ->orWhere('recipient_id', $user->id);
                })
                ->distinct()
                ->pluck('project_id');

            $ids = $visibleIds->merge($dmProjectIds)->unique()->filter()->values();

            if ($ids->isEmpty()) {
                return collect();
            }

            return Project::withoutGlobalScope('agency')
                ->where('agency_id', $user->agency_id)
                ->whereIn('id', $ids)
                ->whereNull('deleted_at')
                ->get(['id', 'client_id', 'agency_id']);
        }

        if ($user->role === 'client') {
            $client = Client::withoutAgencyScope()
                ->where('agency_id', $user->agency_id)
                ->where('contact_user_id', $user->id)
                ->first();

            if (!$client) {
                return collect();
            }

            return Project::withoutGlobalScope('agency')
                ->where('agency_id', $user->agency_id)
                ->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->get(['id', 'client_id', 'agency_id']);
        }

        return collect();
    }

    /**
     * Map a message to the viewer's thread peer key.
     * 0 = overall / project team. null = not visible to this viewer.
     */
    private function threadPeerForViewer(Message $message, int $viewerId): ?int
    {
        if ($message->recipient_id === null) {
            return 0;
        }

        $senderId = (int) $message->sender_id;
        $recipientId = (int) $message->recipient_id;

        if ($senderId !== $viewerId && $recipientId !== $viewerId) {
            return null;
        }

        return $senderId === $viewerId ? $recipientId : $senderId;
    }

    private function messagePayload(Message $m): array
    {
        return [
            'id'              => $m->id,
            'body'            => $m->body,
            'sender_id'       => $m->sender_id,
            'sender_name'     => $m->sender?->name,
            'sender_role'     => $m->sender?->role,
            'recipient_id'    => $m->recipient_id,
            'recipient_name'  => $m->recipient?->name,
            'sentiment_score' => $m->sentiment_score,
            'sentiment_label' => \App\Services\SentimentService::labelFromScore($m->sentiment_score),
            'created_at'      => $m->created_at,
        ];
    }

    private function canAccessProject(Request $request, Project $project): bool
    {
        $user = $request->user();

        if (in_array($user->role, ['admin', 'pm'], true)) {
            return $project->agency_id === $user->agency_id;
        }

        if ($user->role === 'member') {
            if ($project->agency_id !== $user->agency_id) {
                return false;
            }

            if ($project->isVisibleTo($user)) {
                return true;
            }

            // Portal may DM a member who isn't on the project team yet —
            // still allow that member to open / reply on the DM thread.
            return Message::withoutGlobalScope('agency')
                ->where('agency_id', $user->agency_id)
                ->where('project_id', $project->id)
                ->where(function ($q) use ($user) {
                    $q->where('sender_id', $user->id)
                        ->orWhere('recipient_id', $user->id);
                })
                ->exists();
        }

        if ($user->role === 'client') {
            $client = Client::withoutAgencyScope()
                ->where('agency_id', $project->agency_id)
                ->where('contact_user_id', $user->id)
                ->first();

            return $client && $project->client_id === $client->id;
        }

        return false;
    }

    private function canDmPeer(User $user, Project $project, User $peer): bool
    {
        if ($peer->agency_id !== $project->agency_id) {
            return false;
        }

        $clientContactId = $project->client?->contact_user_id
            ?? Client::withoutAgencyScope()->where('id', $project->client_id)->value('contact_user_id');

        if ($user->role === 'client') {
            if ($user->id !== (int) $clientContactId) {
                return false;
            }

            return in_array($peer->role, ['admin', 'pm', 'member'], true)
                && !$peer->isAccessRevoked();
        }

        if (in_array($user->role, ['admin', 'pm', 'member'], true)) {
            if ($user->isAccessRevoked()) {
                return false;
            }

            return (int) $peer->id === (int) $clientContactId
                && !$peer->isAccessRevoked();
        }

        return false;
    }
}
