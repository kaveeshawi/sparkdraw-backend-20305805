<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\UpsellSuggestion;
use App\Models\User;
use App\Services\HealthScoreService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Fills Insights pages: client sentiment messages, upsell suggestions, computed health scores.
 * Safe to re-run — never writes static health scores.
 */
class InsightsDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private Agency $agency;

    public function run(): void
    {
        $this->agency = Agency::where('domain_slug', 'demo')->firstOrFail();

        $messagesCreated = $this->seedClientSentiment();
        $computed = $this->computeAllHealthScores();
        $upsellsCreated = $this->seedUpsellSuggestions();
        $eventsCreated = $this->seedInsightAlerts();

        $this->command->info("✓ Insights demo data — computed scores: {$computed}, messages: {$messagesCreated}, upsells: {$upsellsCreated}, alerts: {$eventsCreated}");
    }

    private function computeAllHealthScores(): int
    {
        $service = app(HealthScoreService::class);
        $computed = 0;

        $projects = Project::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)
            ->where('status', 'active')
            ->get();

        foreach ($projects as $project) {
            if ($service->computeAndSave($project)) {
                $computed++;
            }
        }

        return $computed;
    }

    private function seedClientSentiment(): int
    {
        $created = 0;

        $clients = Client::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)
            ->with('contactUser')
            ->get();

        $sentimentSets = [
            [
                ['days' => 14, 'body' => 'Really happy with progress so far!', 'score' => 0.82],
                ['days' => 7, 'body' => 'Looking great — excited for launch.', 'score' => 0.65],
            ],
            [
                ['days' => 10, 'body' => 'Getting concerned about timeline', 'score' => -0.15],
                ['days' => 5, 'body' => 'Need clearer updates please', 'score' => -0.35],
            ],
            [
                ['days' => 12, 'body' => 'Not happy with latest deliverables', 'score' => -0.45],
                ['days' => 8, 'body' => 'This is taking longer than expected', 'score' => -0.72],
                ['days' => 3, 'body' => 'Considering pausing the project', 'score' => -0.85],
            ],
            [
                ['days' => 6, 'body' => 'Thanks for the quick turnaround.', 'score' => 0.55],
                ['days' => 2, 'body' => 'Can we schedule a review call?', 'score' => 0.20],
            ],
        ];

        foreach ($clients as $index => $client) {
            $contact = $client->contactUser;
            if (!$contact) {
                continue;
            }

            $project = Project::withoutGlobalScopes()
                ->where('agency_id', $this->agency->id)
                ->where('client_id', $client->id)
                ->where('status', 'active')
                ->first();

            if (!$project) {
                continue;
            }

            $hasSentiment = Message::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('sender_id', $contact->id)
                ->whereNotNull('sentiment_score')
                ->exists();

            if ($hasSentiment) {
                continue;
            }

            $set = $sentimentSets[$index % count($sentimentSets)];

            foreach ($set as $entry) {
                Message::create([
                    'agency_id'       => $this->agency->id,
                    'project_id'      => $project->id,
                    'sender_id'       => $contact->id,
                    'body'            => $entry['body'],
                    'sentiment_score' => $entry['score'],
                    'created_at'      => now()->subDays($entry['days']),
                    'updated_at'      => now()->subDays($entry['days']),
                ]);
                $created++;
            }
        }

        return $created;
    }

    private function seedUpsellSuggestions(): int
    {
        $created = 0;

        $targets = Project::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)
            ->where('status', 'active')
            ->with('latestHealthScore')
            ->get()
            ->filter(fn (Project $p) => $p->latestHealthScore?->flag === 'green')
            ->take(4);

        $services = [
            ['type' => 'Social media kit', 'confidence' => 0.82],
            ['type' => 'SEO audit package', 'confidence' => 0.74],
            ['type' => 'Maintenance retainer', 'confidence' => 0.68],
            ['type' => 'Brand guidelines PDF', 'confidence' => 0.61],
        ];

        foreach ($targets as $i => $project) {
            $service = $services[$i % count($services)];

            $exists = UpsellSuggestion::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('service_type', $service['type'])
                ->exists();

            if ($exists) {
                continue;
            }

            UpsellSuggestion::create([
                'agency_id'     => $this->agency->id,
                'project_id'    => $project->id,
                'service_type'  => $service['type'],
                'confidence'    => $service['confidence'],
                'admin_status'  => $i === 0 ? 'pending' : ($i === 1 ? 'pending' : 'approved'),
                'client_status' => 'hidden',
            ]);

            $created++;
        }

        return $created;
    }

    private function seedInsightAlerts(): int
    {
        $created = 0;

        $atRisk = Project::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)
            ->where('status', 'active')
            ->with('latestHealthScore')
            ->get()
            ->filter(fn (Project $p) => in_array($p->latestHealthScore?->flag, ['amber', 'red'], true));

        foreach ($atRisk->take(3) as $project) {
            $type = $project->latestHealthScore?->flag === 'red'
                ? 'health_score_critical'
                : 'deadline_at_risk';

            $exists = ProjectEvent::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('event_type', $type)
                ->exists();

            if ($exists) {
                continue;
            }

            ProjectEvent::log($this->agency->id, $project->id, $type, [
                'score' => $project->latestHealthScore?->score,
                'flag'  => $project->latestHealthScore?->flag,
            ]);

            $created++;
        }

        return $created;
    }
}
