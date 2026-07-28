<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Jobs\SentimentJob;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    use ApiResponse;

    // GET /api/v1/projects/{project}/messages
    public function index(Request $request, Project $project): JsonResponse
    {
        if (!$this->canAccessProject($request, $project)) {
            return $this->forbidden('You do not have access to this project.');
        }

        $messages = $project->messages()
            ->with('sender:id,name,role')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Message $m) => [
                'id'              => $m->id,
                'body'            => $m->body,
                'sender_id'       => $m->sender_id,
                'sender_name'     => $m->sender?->name,
                'sender_role'     => $m->sender?->role,
                'sentiment_score' => $m->sentiment_score,
                'sentiment_label' => \App\Services\SentimentService::labelFromScore($m->sentiment_score),
                'created_at'      => $m->created_at,
            ]);

        return $this->success($messages);
    }

    // POST /api/v1/projects/{project}/messages
    public function store(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        if (!$this->canAccessProject($request, $project)) {
            return $this->forbidden('You do not have access to this project.');
        }

        $user = $request->user();

        $message = Message::create([
            'agency_id'  => $project->agency_id,
            'project_id' => $project->id,
            'sender_id'  => $user->id,
            'body'       => $request->body,
        ]);

        ProjectEvent::log($project->agency_id, $project->id, 'message_sent', [
            'message_id' => $message->id,
            'sender_id'  => $user->id,
            'sender_role'=> $user->role,
        ]);

        SentimentJob::dispatch($message)->onQueue('ai');

        return $this->created([
            'id'              => $message->id,
            'body'            => $message->body,
            'sender_id'       => $message->sender_id,
            'sentiment_score' => null,
            'created_at'      => $message->created_at,
        ], 'Message sent. Sentiment analysis queued.');
    }

    private function canAccessProject(Request $request, Project $project): bool
    {
        $user = $request->user();

        if (in_array($user->role, ['admin', 'pm', 'member'], true)) {
            return $project->agency_id === $user->agency_id;
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
}
