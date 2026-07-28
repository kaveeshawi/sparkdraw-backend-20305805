<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\ProjectEvent;
use App\Services\SentimentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SentimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function handle(SentimentService $sentimentService): void
    {
        $sentimentService->analyzeMessage($this->message);
    }

    public function failed(Throwable $exception): void
    {
        ProjectEvent::log(
            $this->message->agency_id,
            $this->message->project_id,
            'sentiment_job_failed',
            [
                'message_id' => $this->message->id,
                'error'      => $exception->getMessage(),
            ]
        );
    }
}
