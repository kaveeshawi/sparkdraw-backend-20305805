<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Services\SentimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SentimentController extends Controller
{
    use ApiResponse;

    // GET /api/v1/clients/sentiment
    public function clientSentiment(Request $request): JsonResponse
    {
        $clients = Client::with('contactUser:id,name')
            ->orderBy('company_name')
            ->get();

        $data = $clients->map(function (Client $client) {
            $messages = Message::whereHas('project', fn ($q) => $q->where('client_id', $client->id))
                ->where('sender_id', $client->contact_user_id)
                ->whereNotNull('sentiment_score')
                ->orderByDesc('created_at')
                ->get();

            $latest = $messages->first();

            if (!$latest) {
                return [
                    'client_id'       => $client->id,
                    'client_name'     => $client->company_name,
                    'latest_score'    => null,
                    'label'           => null,
                    'trend'           => 'stable',
                    'last_message_at' => null,
                    'at_risk'         => false,
                ];
            }

            $scores = $messages->pluck('sentiment_score')->map(fn ($s) => (float) $s)->all();

            $atRisk = false;
            $projectIds = Project::where('client_id', $client->id)->pluck('id');
            foreach ($projectIds as $projectId) {
                if (SentimentService::isClientAtRisk($client->contact_user_id, $projectId)) {
                    $atRisk = true;
                    break;
                }
            }

            return [
                'client_id'       => $client->id,
                'client_name'     => $client->company_name,
                'latest_score'    => (float) $latest->sentiment_score,
                'label'           => SentimentService::labelFromScore((float) $latest->sentiment_score),
                'trend'           => SentimentService::trendFromScores($scores),
                'last_message_at' => $latest->created_at,
                'at_risk'         => $atRisk,
            ];
        })->values();

        return $this->success($data);
    }

    // GET /api/v1/projects/{project}/sentiment
    public function projectSentiment(Request $request, Project $project): JsonResponse
    {
        $history = $project->messages()
            ->whereNotNull('sentiment_score')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $m) => [
                'score'      => (float) $m->sentiment_score,
                'label'      => SentimentService::labelFromScore((float) $m->sentiment_score),
                'created_at' => $m->created_at,
            ]);

        return $this->success($history);
    }
}
