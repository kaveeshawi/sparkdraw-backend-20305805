<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\Client;
use App\Models\HealthScore;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Revision;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use App\Services\DepartmentBootstrapService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Team + project dummy data tuned for C2 Upsell Engine testing.
 *
 * Creates:
 *  - PM + designers/devs (team roster)
 *  - "Upsell Ready" project (~90% done, green health, positive sentiment)
 *  - "Upsell Warm" project (~75% done, green-ish)
 *  - "Upsell Cold" project (early / negative — should NOT fire upsell)
 *
 * Run: php artisan db:seed --class=UpsellDemoSeeder
 */
class UpsellDemoSeeder extends Seeder
{
    private Agency $agency;

    private User $admin;

    /** @var array<string, User> */
    private array $team = [];

    public function run(): void
    {
        $this->agency = Agency::where('domain_slug', 'demo')->first()
            ?? Agency::firstOrFail();

        $this->admin = User::where('email', 'admin@sparkdraw.test')->first()
            ?? User::where('agency_id', $this->agency->id)->where('role', 'admin')->firstOrFail();

        app(DepartmentBootstrapService::class)->ensureForAgency($this->agency->id);

        $this->seedTeam();
        $ready = $this->seedUpsellReadyProject();
        $warm = $this->seedUpsellWarmProject();
        $cold = $this->seedUpsellColdProject();

        $this->command?->info('✓ Upsell demo team + projects seeded');
        $this->command?->info("  Ready (should fire):  #{$ready->id} {$ready->name}");
        $this->command?->info("  Warm (maybe):         #{$warm->id} {$warm->name}");
        $this->command?->info("  Cold (should not):    #{$cold->id} {$cold->name}");
        $this->command?->info('  Test: POST /api/v1/ai/upsell  { "project_id": '.$ready->id.' }');
        $this->command?->info('  Or UI: Upsell Suggestions / AI Studio — then trigger upsell on Ready project');
    }

    private function seedTeam(): void
    {
        $roster = [
            'pm@sparkdraw.test' => [
                'role' => 'pm',
                'name' => 'Jordan Kim',
                'job_title' => 'Project Manager',
                'department' => 'Management',
            ],
            'alex@sparkdraw.test' => [
                'role' => 'member',
                'name' => 'Alex Chen',
                'job_title' => 'Senior Developer',
                'department' => 'Development',
            ],
            'morgan@sparkdraw.test' => [
                'role' => 'member',
                'name' => 'Morgan Lee',
                'job_title' => 'Lead Designer',
                'department' => 'Design',
            ],
            'sam@sparkdraw.test' => [
                'role' => 'member',
                'name' => 'Sam Rivera',
                'job_title' => 'QA Engineer',
                'department' => 'Development',
            ],
            'riley@sparkdraw.test' => [
                'role' => 'member',
                'name' => 'Riley Okonkwo',
                'job_title' => 'Frontend Developer',
                'department' => 'Development',
            ],
        ];

        foreach ($roster as $email => $attrs) {
            $user = User::firstOrCreate(
                ['email' => $email],
                array_merge($attrs, [
                    'agency_id' => $this->agency->id,
                    'password' => Hash::make('password'),
                    'employment_type' => 'full_time',
                    'availability' => 'available',
                ])
            );

            // Keep agency + profile fields fresh on re-seed
            $user->forceFill(array_merge($attrs, [
                'agency_id' => $this->agency->id,
            ]))->save();

            $this->team[$email] = $user->fresh();
        }

        $this->command?->info('✓ Team: '.count($this->team).' members (password for all: password)');
    }

    private function seedUpsellReadyProject(): Project
    {
        $client = $this->client(
            'Horizon Labs',
            'nina@horizonlabs.test',
            'Nina Patel',
            'vip'
        );

        $project = $this->project(
            $client,
            '[Upsell Ready] Horizon Labs — Portal Launch',
            'Web Development',
            'active',
            18000,
            160,
            '#059669',
            now()->subDays(50),
            now()->addDays(12),
        );

        $this->attachTeam($project, [
            'pm@sparkdraw.test',
            'alex@sparkdraw.test',
            'morgan@sparkdraw.test',
            'sam@sparkdraw.test',
        ]);

        $m1 = $this->milestone($project, 'Discovery', now()->subDays(40), 'completed');
        $m2 = $this->milestone($project, 'Design', now()->subDays(20), 'completed');
        $m3 = $this->milestone($project, 'Build & QA', now()->addDays(10), 'in_progress');

        $pm = $this->team['pm@sparkdraw.test'];
        $dev = $this->team['alex@sparkdraw.test'];
        $des = $this->team['morgan@sparkdraw.test'];
        $qa = $this->team['sam@sparkdraw.test'];

        // ~90% complete (9 done / 10 tasks)
        $tasks = [
            $this->task($project, $m1, 'Kickoff & scope lock', 'done', 'high', 6, 6, $pm),
            $this->task($project, $m1, 'Requirements workshop', 'done', 'high', 8, 8, $pm),
            $this->task($project, $m2, 'Wireframes', 'done', 'medium', 12, 11, $des),
            $this->task($project, $m2, 'UI kit', 'done', 'medium', 14, 13, $des),
            $this->task($project, $m2, 'Client design approval', 'done', 'high', 4, 4, $pm),
            $this->task($project, $m3, 'Auth + roles', 'done', 'high', 20, 18, $dev),
            $this->task($project, $m3, 'Core portal screens', 'done', 'high', 28, 26, $dev),
            $this->task($project, $m3, 'Integrations', 'done', 'medium', 16, 15, $dev),
            $this->task($project, $m3, 'QA pass', 'done', 'high', 12, 10, $qa),
            $this->task($project, $m3, 'Launch checklist', 'in_progress', 'high', 8, 3, $pm),
        ];

        $this->timeLogs([
            [$tasks[5], $dev, 18, 12],
            [$tasks[6], $dev, 26, 8],
            [$tasks[7], $dev, 15, 5],
            [$tasks[8], $qa, 10, 3],
        ]);

        $contact = User::find($client->contact_user_id);
        $this->revision($project, $client, $contact, 1, 'Looks great — minor typography tweaks only.', 'resolved', [
            'title' => 'Typography polish',
            'category' => 'ui',
            'priority' => 'low',
            'subtasks' => ['Adjust heading scale'],
        ], 21);
        $this->revision($project, $client, $contact, 2, 'Approved to move to launch prep.', 'resolved', null, 8);

        $asset = Asset::firstOrCreate(
            [
                'agency_id' => $this->agency->id,
                'project_id' => $project->id,
                'file_path' => 'demo/horizon-portal-v1.pdf',
            ],
            [
                'uploader_id' => $this->admin->id,
                'original_name' => 'portal-preview.pdf',
                'version' => 1,
                'is_deliverable' => true,
            ]
        );

        Approval::firstOrCreate(
            [
                'agency_id' => $this->agency->id,
                'project_id' => $project->id,
                'deliverable_id' => $asset->id,
            ],
            [
                'client_id' => $client->id,
                'requested_by_id' => $pm->id,
                'status' => 'approved',
                'requested_at' => now()->subDays(10),
                'responded_at' => now()->subDays(9),
                'approval_lag_hours' => 18,
            ]
        );

        $this->messages($project, $contact, [
            [18, 'Really impressed with the portal so far!', 0.78],
            [12, 'Design feels premium — thank you.', 0.72],
            [6, 'Happy to approve and move toward launch.', 0.85],
            [2, 'Looking forward to go-live next week.', 0.68],
        ]);

        Invoice::firstOrCreate(
            ['agency_id' => $this->agency->id, 'invoice_number' => 'INV-UPSELL-001'],
            [
                'client_id' => $client->id,
                'project_id' => $project->id,
                'amount' => 12000,
                'line_items' => [['description' => 'Portal build — phases 1–2', 'quantity' => 1, 'rate' => 12000]],
                'status' => 'paid',
                'due_date' => now()->subWeeks(2),
                'paid_at' => now()->subWeeks(1),
            ]
        );

        Invoice::firstOrCreate(
            ['agency_id' => $this->agency->id, 'invoice_number' => 'INV-UPSELL-002'],
            [
                'client_id' => $client->id,
                'project_id' => $project->id,
                'amount' => 4000,
                'line_items' => [['description' => 'Launch + handover', 'quantity' => 1, 'rate' => 4000]],
                'status' => 'sent',
                'due_date' => now()->addDays(14),
            ]
        );

        $this->setHealth($project, 88, 'green', [
            'On track for launch',
            'Positive client sentiment',
            'Low revision pressure',
        ]);

        return $project->fresh();
    }

    private function seedUpsellWarmProject(): Project
    {
        $client = $this->client(
            'Cedar Retail',
            'ops@cedarretail.test',
            'Chris Oak',
            null
        );

        $project = $this->project(
            $client,
            '[Upsell Warm] Cedar Retail — Storefront Refresh',
            'E-Commerce',
            'active',
            12000,
            100,
            '#2563eb',
            now()->subDays(35),
            now()->addDays(25),
        );

        $this->attachTeam($project, [
            'pm@sparkdraw.test',
            'riley@sparkdraw.test',
            'morgan@sparkdraw.test',
        ]);

        $m1 = $this->milestone($project, 'Discovery', now()->subDays(25), 'completed');
        $m2 = $this->milestone($project, 'Build', now()->addDays(20), 'in_progress');

        $pm = $this->team['pm@sparkdraw.test'];
        $dev = $this->team['riley@sparkdraw.test'];
        $des = $this->team['morgan@sparkdraw.test'];

        // 6/8 done = 75%
        $this->task($project, $m1, 'Audit current storefront', 'done', 'medium', 8, 8, $pm);
        $this->task($project, $m1, 'IA + flows', 'done', 'high', 10, 9, $des);
        $this->task($project, $m2, 'Homepage redesign', 'done', 'high', 16, 15, $des);
        $this->task($project, $m2, 'PLP templates', 'done', 'medium', 14, 12, $dev);
        $this->task($project, $m2, 'Checkout polish', 'done', 'high', 18, 16, $dev);
        $this->task($project, $m2, 'Analytics events', 'done', 'medium', 8, 7, $dev);
        $this->task($project, $m2, 'Performance pass', 'in_progress', 'medium', 10, 4, $dev);
        $this->task($project, $m2, 'UAT fixes', 'todo', 'high', 8, 0, $pm);

        $contact = User::find($client->contact_user_id);
        $this->revision($project, $client, $contact, 1, 'Homepage looks solid.', 'resolved', null, 10);
        $this->messages($project, $contact, [
            [14, 'Happy with the direction.', 0.55],
            [5, 'Checkout feels smoother already.', 0.42],
        ]);

        Invoice::firstOrCreate(
            ['agency_id' => $this->agency->id, 'invoice_number' => 'INV-UPSELL-003'],
            [
                'client_id' => $client->id,
                'project_id' => $project->id,
                'amount' => 6000,
                'line_items' => [['description' => 'Design + build deposit', 'quantity' => 1, 'rate' => 6000]],
                'status' => 'paid',
                'due_date' => now()->subWeeks(3),
                'paid_at' => now()->subWeeks(2),
            ]
        );

        $this->setHealth($project, 72, 'green', ['Steady progress', 'Healthy sentiment']);

        return $project->fresh();
    }

    private function seedUpsellColdProject(): Project
    {
        $client = $this->client(
            'Redline Logistics',
            'hello@redlinelogistics.test',
            'Dana Red',
            null
        );

        $project = $this->project(
            $client,
            '[Upsell Cold] Redline — Ops Dashboard (early)',
            'App Development',
            'started',
            25000,
            200,
            '#dc2626',
            now()->subDays(10),
            now()->addDays(60),
        );

        $this->attachTeam($project, [
            'pm@sparkdraw.test',
            'alex@sparkdraw.test',
        ]);

        $m1 = $this->milestone($project, 'Discovery', now()->addDays(5), 'in_progress');
        $pm = $this->team['pm@sparkdraw.test'];
        $dev = $this->team['alex@sparkdraw.test'];

        // Early project — 1/6 done
        $this->task($project, $m1, 'Stakeholder interviews', 'done', 'high', 8, 6, $pm);
        $this->task($project, $m1, 'Data model draft', 'in_progress', 'high', 12, 4, $dev);
        $this->task($project, $m1, 'Wireframes', 'todo', 'medium', 16, 0, $this->team['morgan@sparkdraw.test']);
        $this->task($project, $m1, 'API spike', 'todo', 'medium', 20, 0, $dev);
        $this->task($project, $m1, 'Auth prototype', 'todo', 'high', 14, 0, $dev);
        $this->task($project, $m1, 'Pilot review', 'todo', 'low', 4, 0, $pm);

        $contact = User::find($client->contact_user_id);
        $this->messages($project, $contact, [
            [4, 'Still unclear on scope — need more detail.', -0.2],
            [1, 'Worried we are moving too slowly.', -0.35],
        ]);

        $this->setHealth($project, 48, 'amber', [
            'Early stage — low completion',
            'Sentiment cooling',
        ]);

        return $project->fresh();
    }

    private function client(string $company, string $email, string $name, ?string $tier): Client
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'agency_id' => $this->agency->id,
                'role' => 'client',
                'name' => $name,
                'password' => Hash::make('password'),
            ]
        );

        $client = Client::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $this->agency->id, 'company_name' => $company],
            ['contact_user_id' => $user->id, 'tier' => $tier]
        );

        if ($tier && $client->tier !== $tier) {
            $client->update(['tier' => $tier]);
        }

        if ((int) $client->contact_user_id !== (int) $user->id) {
            $client->update(['contact_user_id' => $user->id]);
        }

        return $client->fresh();
    }

    private function project(
        Client $client,
        string $name,
        string $type,
        string $status,
        float $budget,
        int $hours,
        string $color,
        $start,
        $end,
    ): Project {
        $existing = Project::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            // Rebuild child data for a clean upsell test surface
            $existing->tasks()->withoutGlobalScopes()->delete();
            $existing->milestones()->withoutGlobalScopes()->delete();
            $existing->messages()->withoutGlobalScopes()->delete();
            $existing->revisions()->withoutGlobalScopes()->delete();
            $existing->approvals()->withoutGlobalScopes()->delete();
            HealthScore::where('project_id', $existing->id)->delete();

            $existing->update([
                'client_id' => $client->id,
                'type' => $type,
                'status' => $status,
                'budget' => $budget,
                'estimated_hours' => $hours,
                'start_date' => $start,
                'end_date' => $end,
                'color' => $color,
            ]);

            return $existing->fresh();
        }

        return Project::create([
            'agency_id' => $this->agency->id,
            'client_id' => $client->id,
            'name' => $name,
            'type' => $type,
            'status' => $status,
            'budget' => $budget,
            'estimated_hours' => $hours,
            'start_date' => $start,
            'end_date' => $end,
            'color' => $color,
            'priority' => 'high',
            'description' => 'Seeded for AI upsell engine testing.',
        ]);
    }

    private function attachTeam(Project $project, array $emails): void
    {
        $ids = collect($emails)
            ->map(fn ($email) => $this->team[$email]->id ?? null)
            ->filter()
            ->values()
            ->all();

        $project->teamMembers()->sync($ids);
    }

    private function milestone(Project $project, string $title, $due, string $status): Milestone
    {
        return Milestone::create([
            'agency_id' => $this->agency->id,
            'project_id' => $project->id,
            'title' => $title,
            'due_date' => $due,
            'status' => $status,
        ]);
    }

    private function task(
        Project $project,
        Milestone $m,
        string $title,
        string $status,
        string $priority,
        int $est,
        int $act,
        User $assignee,
    ): Task {
        return Task::create([
            'agency_id' => $this->agency->id,
            'project_id' => $project->id,
            'milestone_id' => $m->id,
            'assignee_id' => $assignee->id,
            'title' => $title,
            'status' => $status,
            'priority' => $priority,
            'estimated_hours' => $est,
            'actual_hours' => $act,
            'deadline' => $m->due_date,
        ]);
    }

    private function timeLogs(array $entries): void
    {
        foreach ($entries as [$task, $user, $hours, $daysAgo]) {
            TimeLog::create([
                'agency_id' => $this->agency->id,
                'task_id' => $task->id,
                'user_id' => $user->id,
                'hours' => $hours,
                'logged_date' => now()->subDays($daysAgo)->toDateString(),
            ]);
        }
    }

    private function revision(
        Project $project,
        Client $client,
        User $submitter,
        int $round,
        string $feedback,
        string $status,
        ?array $ticket,
        int $daysAgo = 14,
    ): void {
        $revision = Revision::updateOrCreate(
            [
                'agency_id' => $this->agency->id,
                'project_id' => $project->id,
                'round_number' => $round,
            ],
            [
                'client_id' => $client->id,
                'submitted_by_id' => $submitter->id,
                'feedback_text' => $feedback,
                'ai_ticket_json' => $ticket,
                'status' => $status,
            ]
        );

        $revision->created_at = now()->subDays($daysAgo);
        $revision->updated_at = now()->subDays($daysAgo);
        $revision->save();
    }

    private function messages(Project $project, User $clientUser, array $entries): void
    {
        Message::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('sender_id', $clientUser->id)
            ->delete();

        foreach ($entries as [$daysAgo, $body, $score]) {
            $message = new Message([
                'agency_id' => $this->agency->id,
                'project_id' => $project->id,
                'sender_id' => $clientUser->id,
                'body' => $body,
                'sentiment_score' => $score,
            ]);
            $message->created_at = now()->subDays($daysAgo);
            $message->updated_at = now()->subDays($daysAgo);
            $message->save();
        }
    }

    private function setHealth(Project $project, int $score, string $flag, array $reasons): void
    {
        HealthScore::where('project_id', $project->id)->delete();

        HealthScore::create([
            'agency_id' => $this->agency->id,
            'project_id' => $project->id,
            'score' => $score,
            'flag' => $flag,
            'reasons' => $reasons,
            'computed_at' => now(),
        ]);
    }
}
