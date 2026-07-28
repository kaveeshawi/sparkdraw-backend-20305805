<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\SentimentService;
use Illuminate\Console\Command;

class ComputeSentiment extends Command
{
    protected $signature = 'sentiment:compute';

    protected $description = 'Analyze sentiment for messages missing a sentiment score';

    public function handle(SentimentService $sentimentService): int
    {
        $messages = Message::withoutAgencyScope()
            ->whereNull('sentiment_score')
            ->orderBy('id')
            ->get();

        $processed = 0;

        foreach ($messages as $message) {
            if ($sentimentService->analyzeMessage($message)) {
                $processed++;
            }
        }

        $this->info("Processed {$processed} message(s) for sentiment analysis.");

        return self::SUCCESS;
    }
}
