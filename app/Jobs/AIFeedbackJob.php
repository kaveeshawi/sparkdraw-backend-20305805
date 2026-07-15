<?php

namespace App\Jobs;

use App\Models\ProjectEvent;
use App\Models\Revision;
use App\Services\AIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class AIFeedbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Revision $revision) {}

    public function handle(AIService $aiService): void
    {
        $revision = $this->revision;

        $revision->loadMissing('project');

        $result = $aiService->analyzeFeedback(
            feedbackText: $revision->feedback_text,
            projectType:  $revision->project?->type ?? 'general',
            projectId:    $revision->project_id,
        );

        if (!empty($result['success']) && !empty($result['data'])) {
            $ticket = $result['data'];
            $revision->update(['ai_ticket_json' => $ticket]);

            ProjectEvent::log(
                $revision->agency_id,
                $revision->project_id,
                'ai_ticket_generated',
                [
                    'revision_id'     => $revision->id,
                    'round_number'    => $revision->round_number,
                    'title'           => $ticket['title'] ?? null,
                    'category'        => $ticket['category'] ?? null,
                    'priority'        => $ticket['priority'] ?? null,
                    'estimated_hours' => $ticket['estimated_hours'] ?? null,
                ]
            );
        } else {
            $errorMsg = $result['message'] ?? 'Unknown AI error';

            ProjectEvent::log(
                $revision->agency_id,
                $revision->project_id,
                'ai_ticket_failed',
                [
                    'revision_id'   => $revision->id,
                    'error_message' => $errorMsg,
                ]
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        ProjectEvent::log(
            $this->revision->agency_id,
            $this->revision->project_id,
            'ai_feedback_job_failed',
            [
                'revision_id' => $this->revision->id,
                'error'       => $exception->getMessage(),
            ]
        );
    }
}
