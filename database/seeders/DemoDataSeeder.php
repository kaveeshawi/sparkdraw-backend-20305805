<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\Revision;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\UpsellSuggestion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    private Agency $agency;
    private User $admin;

    public function run(): void
    {
        $this->agency = Agency::where('domain_slug', 'demo')->firstOrFail();
        $this->admin  = User::where('email', 'admin@sparkdraw.test')->firstOrFail();

        if (Project::withoutGlobalScopes()->where('name', 'NovaTech — Brand Redesign')->exists()) {
            $this->command->info('Demo data already present — filling insights gaps.');
            $this->call(InsightsDemoSeeder::class);
            return;
        }

        $brightClient = $this->client('BrightFuture Inc', 'bright@brightfuture.test', 'Tom Bright', 'vip');
        $greenClient  = $this->client('GreenLeaf Co', 'mia@greenleaf.test', 'Mia Chen');

        $novaClient = Client::withoutGlobalScopes()->where('company_name', 'NovaTech Ltd')->first();
        if ($novaClient) {
            $novaClient->update(['tier' => 'enterprise']);
        } else {
            $novaClient = $this->client('NovaTech Ltd', 'sarah@novatech.test', 'Sarah Mitchell', 'enterprise');
        }

        $p1 = $this->project($novaClient, 'NovaTech — Brand Redesign', 'branding', 'active', 12000, 90, '#802AEE');
        $p2 = $this->project($brightClient, 'BrightFuture — Web App', 'web', 'active', 22000, 120, '#2563eb');
        $p3 = $this->project($greenClient, 'GreenLeaf — Social Kit', 'marketing', 'active', 8000, 50, '#dc2626');

        $this->seedNovaTech($p1, $novaClient);
        $this->seedBrightFuture($p2, $brightClient);
        $this->seedGreenLeaf($p3, $greenClient);
        $this->seedInvoices($novaClient, $brightClient, $greenClient, $p1, $p2, $p3);
        $this->seedUpsellAndAlerts($p1, $p2, $p3);
        $this->computeHealthScores([$p1, $p2, $p3]);

        $this->command->info('✓ Rich demo data seeded (3 projects, tasks, computed health scores, alerts)');
    }

    private function computeHealthScores(array $projects): void
    {
        $service = app(\App\Services\HealthScoreService::class);

        foreach ($projects as $project) {
            $service->computeAndSave($project->fresh());
        }
    }

    private function client(string $company, string $email, string $name, ?string $tier = null): Client
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['agency_id' => $this->agency->id, 'role' => 'client', 'name' => $name, 'password' => Hash::make('password')]
        );

        $client = Client::firstOrCreate(
            ['agency_id' => $this->agency->id, 'company_name' => $company],
            ['contact_user_id' => $user->id, 'tier' => $tier]
        );

        if ($tier && $client->tier !== $tier) {
            $client->update(['tier' => $tier]);
        }

        return $client;
    }

    private function project(Client $client, string $name, string $type, string $status, float $budget, int $hours, string $color): Project
    {
        return Project::create([
            'agency_id' => $this->agency->id, 'client_id' => $client->id,
            'name' => $name, 'type' => $type, 'status' => $status,
            'budget' => $budget, 'estimated_hours' => $hours,
            'start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(2),
            'color' => $color,
        ]);
    }

    private function seedNovaTech(Project $project, Client $client): void
    {
        $contact = User::find($client->contact_user_id);
        $m1 = $this->milestone($project, 'Discovery', now()->subMonth(), 'completed');
        $m2 = $this->milestone($project, 'Visual Identity', now()->addWeeks(2), 'in_progress');

        $tasks = [
            $this->task($project, $m1, 'Brand workshop', 'done', 'high', 8, 7, $this->admin),
            $this->task($project, $m1, 'Competitor research', 'done', 'medium', 6, 6, $this->admin),
            $this->task($project, $m2, 'Logo concepts', 'done', 'high', 12, 11, $this->admin),
            $this->task($project, $m2, 'Colour system', 'in_progress', 'medium', 8, 5, $this->admin),
            $this->task($project, $m2, 'Brand guidelines', 'todo', 'medium', 10, 0, $this->admin),
        ];
        $this->timeLogs($project, [[$tasks[0], $this->admin, 7, 40], [$tasks[2], $this->admin, 11, 25], [$tasks[3], $this->admin, 5, 10]]);

        $this->revision($project, $client, $contact, 1, 'Love the logo direction!', 'resolved', ['title' => 'Logo refinement', 'category' => 'branding', 'priority' => 'low', 'subtasks' => []]);
        $this->messages($project, $contact, [[14, 'Really happy with progress so far!', 0.82], [7, 'Can we see dark mode version?', 0.55]]);
    }

    private function seedBrightFuture(Project $project, Client $client): void
    {
        $contact = User::find($client->contact_user_id);
        $m1 = $this->milestone($project, 'UX & Wireframes', now()->subWeeks(4), 'completed');
        $m2 = $this->milestone($project, 'Frontend Build', now()->addWeeks(3), 'in_progress');

        $tasks = [
            $this->task($project, $m1, 'User flows', 'done', 'high', 10, 12, $this->admin),
            $this->task($project, $m2, 'Dashboard UI', 'in_progress', 'high', 24, 22, $this->admin),
            $this->task($project, $m2, 'API integration', 'in_progress', 'high', 20, 18, $this->admin),
            $this->task($project, $m2, 'Auth module', 'todo', 'medium', 12, 0, $this->admin),
        ];
        $this->timeLogs($project, [[$tasks[1], $this->admin, 40, 35], [$tasks[2], $this->admin, 38, 30], [$tasks[0], $this->admin, 28, 45]]);

        $this->revision($project, $client, $contact, 1, 'Dashboard needs more whitespace', 'acknowledged', ['title' => 'Spacing audit', 'category' => 'ui', 'priority' => 'medium', 'subtasks' => ['Increase padding']]);
        $this->revision($project, $client, $contact, 2, 'Can we add real-time notifications?', 'pending', ['title' => 'Notifications feature', 'category' => 'feature', 'priority' => 'high', 'subtasks' => []]);
        $this->revision($project, $client, $contact, 3, 'Third round — animation feels sluggish', 'pending', null);
        $this->messages($project, $contact, [[10, 'Getting concerned about timeline', -0.15], [5, 'Third revision still not right', -0.35], [2, 'Need this fixed before launch', -0.55]]);

        ProjectEvent::log($this->agency->id, $project->id, 'revision_risk_detected', ['round' => 3]);
        ProjectEvent::log($this->agency->id, $project->id, 'ai_ticket_generated', ['revision_id' => 1]);
    }

    private function seedGreenLeaf(Project $project, Client $client): void
    {
        $contact = User::find($client->contact_user_id);
        $m1 = $this->milestone($project, 'Strategy', now()->subWeeks(6), 'completed');
        $m2 = $this->milestone($project, 'Content Creation', now()->subWeeks(1), 'in_progress'); // overdue

        $tasks = [
            $this->task($project, $m1, 'Audience research', 'done', 'high', 6, 8, $this->admin),
            $this->task($project, $m2, 'Instagram templates', 'in_progress', 'high', 14, 16, $this->admin),
            $this->task($project, $m2, 'Copywriting', 'todo', 'medium', 8, 0, $this->admin),
        ];
        $this->timeLogs($project, [[$tasks[1], $this->admin, 16, 20], [$tasks[0], $this->admin, 8, 40]]);

        foreach ([1, 2, 3, 4] as $round) {
            $this->revision($project, $client, $contact, $round, "Revision round {$round} — adjust visual tone", $round < 3 ? 'resolved' : 'pending', null);
        }

        $this->messages($project, $contact, [[12, 'Not happy with latest deliverables', -0.45], [8, 'This is the fourth revision now', -0.72], [3, 'Considering pausing the project', -0.85]]);
        ProjectEvent::log($this->agency->id, $project->id, 'deadline_at_risk', ['milestone' => 'Content Creation']);
        ProjectEvent::log($this->agency->id, $project->id, 'client_sentiment_declining', ['client_id' => $client->id]);
    }

    private function seedInvoices(Client $nova, Client $bright, Client $green, Project $p1, Project $p2, Project $p3): void
    {
        Invoice::create(['agency_id' => $this->agency->id, 'client_id' => $nova->id, 'project_id' => $p1->id,
            'invoice_number' => 'INV-2026-001', 'amount' => 4000, 'line_items' => [['description' => 'Phase 1', 'quantity' => 1, 'rate' => 4000]],
            'status' => 'paid', 'due_date' => now()->subMonth(), 'paid_at' => now()->subWeeks(2)]);

        Invoice::create(['agency_id' => $this->agency->id, 'client_id' => $bright->id, 'project_id' => $p2->id,
            'invoice_number' => 'INV-2026-002', 'amount' => 5500, 'line_items' => [['description' => 'Sprint 2', 'quantity' => 1, 'rate' => 5500]],
            'status' => 'sent', 'due_date' => now()->addDays(14)]);

        Invoice::create(['agency_id' => $this->agency->id, 'client_id' => $green->id, 'project_id' => $p3->id,
            'invoice_number' => 'INV-2026-003', 'amount' => 2000, 'line_items' => [['description' => 'Deposit', 'quantity' => 1, 'rate' => 2000]],
            'status' => 'sent', 'due_date' => now()->subDays(5)]); // overdue via displayStatus
    }

    private function seedUpsellAndAlerts(Project $p1, Project $p2, Project $p3): void
    {
        UpsellSuggestion::create([
            'agency_id' => $this->agency->id, 'project_id' => $p1->id,
            'service_type' => 'Social media kit', 'confidence' => 0.82,
            'admin_status' => 'pending', 'client_status' => 'hidden',
        ]);
        ProjectEvent::log($this->agency->id, $p2->id, 'ai_ticket_generated', ['title' => 'Notifications feature']);
    }

    private function milestone(Project $project, string $title, $due, string $status): Milestone
    {
        return Milestone::create(['agency_id' => $this->agency->id, 'project_id' => $project->id, 'title' => $title, 'due_date' => $due, 'status' => $status]);
    }

    private function task(Project $project, Milestone $m, string $title, string $status, string $priority, int $est, int $act, User $assignee): Task
    {
        return Task::create([
            'agency_id' => $this->agency->id, 'project_id' => $project->id, 'milestone_id' => $m->id,
            'assignee_id' => $assignee->id, 'title' => $title, 'status' => $status, 'priority' => $priority,
            'estimated_hours' => $est, 'actual_hours' => $act, 'deadline' => $m->due_date,
        ]);
    }

    private function timeLogs(Project $project, array $entries): void
    {
        foreach ($entries as [$task, $user, $hours, $daysAgo]) {
            TimeLog::create(['agency_id' => $this->agency->id, 'task_id' => $task->id, 'user_id' => $user->id,
                'hours' => $hours, 'logged_date' => now()->subDays($daysAgo)->toDateString()]);
        }
    }

    private function revision(Project $project, Client $client, User $submitter, int $round, string $feedback, string $status, ?array $ticket): void
    {
        Revision::create(['agency_id' => $this->agency->id, 'project_id' => $project->id, 'client_id' => $client->id,
            'submitted_by_id' => $submitter->id, 'round_number' => $round, 'feedback_text' => $feedback,
            'ai_ticket_json' => $ticket, 'status' => $status]);
    }

    private function messages(Project $project, User $clientUser, array $entries): void
    {
        foreach ($entries as [$daysAgo, $body, $score]) {
            Message::create(['agency_id' => $this->agency->id, 'project_id' => $project->id, 'sender_id' => $clientUser->id,
                'body' => $body, 'sentiment_score' => $score, 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
        }
    }
}
