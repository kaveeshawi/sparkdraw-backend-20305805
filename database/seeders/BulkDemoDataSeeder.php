<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Adds a bulk of additional clients + projects across varied types, on top of
// DemoDataSeeder / DummyClientsSeeder, so the dashboard has enough volume to
// look like a real multi-client agency rather than 3-6 seed rows.
class BulkDemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    private array $projectTypes = [
        'Web Development', 'Branding', 'Marketing', 'SEO Audit', 'UI/UX Design',
        'App Development', 'E-Commerce', 'Content Strategy', 'Social Media Management', 'Video Production',
    ];

    private array $companies = [
        'Lumen Digital', 'Nordic Peak Studio', 'Craftline Interiors', 'Solace Wellness',
        'Ironclad Logistics', 'Verdant Foods', 'Skyline Realty', 'Pulse Fitness', 'Amberly Fashion',
        'Cobalt Analytics', 'Harborlight Hotels', 'Fernwood Books', 'Quartz Finance', 'Meridian Legal',
        'Driftwood Coffee Co', 'Vantage Robotics', 'Willow and Vine Florist',
    ];

    private array $colorPalette = ['#802AEE', '#2563eb', '#059669', '#d97706', '#dc2626', '#db2777', '#0891b2'];

    public function run(): void
    {
        $agency = Agency::where('domain_slug', 'demo')->firstOrFail();
        $statusCycle = ['active', 'active', 'active', 'active', 'completed'];
        $now = now();
        $created = 0;

        foreach ($this->companies as $i => $companyName) {
            $slug = Str::slug($companyName);
            $email = "{$slug}@client.test";

            $contactUser = User::firstOrCreate(
                ['email' => $email],
                [
                    'agency_id' => $agency->id,
                    'role'      => 'client',
                    'name'      => "{$companyName} Contact",
                    'password'  => Hash::make('password'),
                ]
            );

            $client = Client::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agency->id, 'company_name' => $companyName],
                ['contact_user_id' => $contactUser->id]
            );

            // Skip if this client already has a project (idempotent re-run)
            if (Project::withoutGlobalScopes()->where('client_id', $client->id)->exists()) {
                continue;
            }

            $type      = $this->projectTypes[$i % count($this->projectTypes)];
            $status    = $statusCycle[$i % count($statusCycle)];
            $startDate = $now->copy()->subDays(random_int(10, 120));
            $endDate   = $startDate->copy()->addDays(random_int(30, 90));

            $project = Project::create([
                'agency_id'       => $agency->id,
                'client_id'       => $client->id,
                'name'            => "{$companyName} — {$type}",
                'type'            => $type,
                'status'          => $status,
                'budget'          => random_int(3, 18) * 1000,
                'estimated_hours' => random_int(40, 180),
                'start_date'      => $startDate,
                'end_date'        => $endDate,
                'color'           => $this->colorPalette[$i % count($this->colorPalette)],
            ]);

            Milestone::create([
                'agency_id'  => $agency->id,
                'project_id' => $project->id,
                'title'      => 'Project Kickoff',
                'due_date'   => $startDate,
                'status'     => 'completed',
            ]);

            Milestone::create([
                'agency_id'  => $agency->id,
                'project_id' => $project->id,
                'title'      => 'Delivery & Review',
                'due_date'   => $endDate,
                'status'     => $status === 'completed' ? 'completed' : 'pending',
            ]);

            $created++;
        }

        $this->command->info("✓ Bulk-seeded {$created} additional clients + projects across " . count($this->projectTypes) . ' project types');
    }
}
