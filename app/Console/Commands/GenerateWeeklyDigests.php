<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Revision;
use App\Services\AIService;
use Illuminate\Console\Command;

class GenerateWeeklyDigests extends Command
{
    protected $signature = 'digests:generate';

    protected $description = 'Generate weekly AI digests for all active projects';

    public function handle(AIService $aiService): int
    {
        $projects = Project::withoutAgencyScope()
            ->where('status', 'active')
            ->with('client:id,company_name')
            ->get();

        $generated = 0;

        foreach ($projects as $project) {
            $events = ProjectEvent::withoutAgencyScope()
                ->where('project_id', $project->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at')
                ->get();

            $eventsSummary = $events->map(function (ProjectEvent $event) {
                $date = $event->created_at->format('Y-m-d');
                $meta = !empty($event->metadata)
                    ? json_encode($event->metadata)
                    : 'no details';

                return "{$date}: {$event->event_type} — {$meta}";
            })->implode("\n");

            if ($eventsSummary === '') {
                $eventsSummary = 'No project activity recorded this week.';
            }

            $result = $aiService->generateDigest(
                $project->name,
                $project->client->company_name ?? 'Client',
                $eventsSummary,
            );

            if (empty($result['success']) || empty($result['data']['summary'])) {
                continue;
            }

            $summary = $result['data']['summary'];
            $digestDate = now()->format('Y-m-d');
            $roundNumber = $project->revisions()->count() + 1;

            Revision::create([
                'agency_id'       => $project->agency_id,
                'project_id'      => $project->id,
                'client_id'       => $project->client_id,
                'submitted_by_id' => null,
                'round_number'    => $roundNumber,
                'feedback_text'   => "Weekly Digest — {$digestDate}",
                'ai_ticket_json'  => ['summary' => $summary],
                'status'          => 'pending',
            ]);

            ProjectEvent::log($project->agency_id, $project->id, 'weekly_digest_generated', [
                'digest_date' => $digestDate,
                'summary'     => $summary,
            ]);

            $generated++;
        }

        $this->info("Generated {$generated} weekly digest(s).");

        return self::SUCCESS;
    }
}
