<?php

namespace App\Services;

use App\Models\Message;
use App\Models\ProjectEvent;

class SentimentService
{
    public function __construct(private readonly AIService $aiService) {}

    public function analyzeMessage(Message $message): bool
    {
        $result = $this->aiService->getSentiment(
            $message->body,
            $message->project_id,
            $message->id,
        );

        if (empty($result['success']) || !isset($result['data']['score'])) {
            return false;
        }

        $message->update(['sentiment_score' => (float) $result['data']['score']]);

        $this->checkConsecutiveNegatives($message->fresh());

        return true;
    }

    public function checkConsecutiveNegatives(Message $message): void
    {
        $message->loadMissing('project.client');
        $client = $message->project?->client;

        if (!$client) {
            return;
        }

        $recent = Message::withoutAgencyScope()
            ->where('project_id', $message->project_id)
            ->where('sender_id', $client->contact_user_id)
            ->whereNotNull('sentiment_score')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        if ($recent->count() < 3) {
            return;
        }

        if (!$recent->every(fn (Message $m) => $m->sentiment_score < -0.3)) {
            return;
        }

        ProjectEvent::log($message->agency_id, $message->project_id, 'client_sentiment_declining', [
            'client_id'  => $client->id,
            'project_id' => $message->project_id,
            'avg_score'  => round((float) $recent->avg('sentiment_score'), 4),
        ]);
    }

    public static function labelFromScore(?float $score): string
    {
        if ($score === null) {
            return 'neutral';
        }
        if ($score > 0.3) {
            return 'positive';
        }
        if ($score > -0.3) {
            return 'neutral';
        }

        return 'negative';
    }

    public static function trendFromScores(array $scores): string
    {
        if (count($scores) < 2) {
            return 'stable';
        }

        $recent = array_slice($scores, 0, min(3, count($scores)));
        $older  = array_slice($scores, min(3, count($scores)));

        if (empty($older)) {
            return 'stable';
        }

        $recentAvg = array_sum($recent) / count($recent);
        $olderAvg  = array_sum($older) / count($older);
        $delta     = $recentAvg - $olderAvg;

        if ($delta > 0.1) {
            return 'improving';
        }
        if ($delta < -0.1) {
            return 'declining';
        }

        return 'stable';
    }

    public static function isClientAtRisk(int $contactUserId, int $projectId): bool
    {
        $recent = Message::withoutAgencyScope()
            ->where('project_id', $projectId)
            ->where('sender_id', $contactUserId)
            ->whereNotNull('sentiment_score')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        return $recent->count() === 3
            && $recent->every(fn (Message $m) => $m->sentiment_score < -0.3);
    }
}
