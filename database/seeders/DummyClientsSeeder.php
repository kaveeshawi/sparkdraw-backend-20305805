<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DummyClientsSeeder extends Seeder
{
    public function run(): void
    {
        $agency = Agency::where('domain_slug', 'demo')->first();

        if (!$agency) {
            $this->command->warn('Demo agency not found — run DatabaseSeeder first.');
            return;
        }

        $clients = [
            [
                'company_name' => 'NovaTech Ltd',
                'tier'         => 'enterprise',
                'job_title'    => 'Marketing Director',
                'user'         => ['name' => 'Sarah Mitchell', 'email' => 'sarah@novatech.test'],
                'project'      => [
                    'name'            => 'NovaTech Brand Identity',
                    'type'            => 'branding',
                    'status'          => 'active',
                    'budget'          => 8500,
                    'estimated_hours' => 55,
                    'color'           => '#3B82F6',
                ],
            ],
            [
                'company_name' => 'Bluewave Media',
                'tier'         => 'vip',
                'job_title'    => 'Creative Lead',
                'user'         => ['name' => 'James Rivera', 'email' => 'james@bluewave.test'],
                'project'      => [
                    'name'            => 'Bluewave Social Campaign',
                    'type'            => 'marketing',
                    'status'          => 'active',
                    'budget'          => 6000,
                    'estimated_hours' => 40,
                    'color'           => '#10B981',
                ],
            ],
            [
                'company_name' => 'Stellar Design Co',
                'tier'         => null,
                'job_title'    => 'Founder',
                'user'         => ['name' => 'Priya Nair', 'email' => 'priya@stellar.test'],
                'project'      => [
                    'name'            => 'Stellar E-commerce Build',
                    'type'            => 'web',
                    'status'          => 'planning',
                    'budget'          => 18000,
                    'estimated_hours' => 120,
                    'color'           => '#F59E0B',
                ],
            ],
        ];

        foreach ($clients as $entry) {
            $client = Client::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('company_name', $entry['company_name'])
                ->first();

            if (!$client) {
                $user = User::firstOrCreate(
                    ['email' => $entry['user']['email']],
                    [
                        'agency_id' => $agency->id,
                        'role'      => 'client',
                        'name'      => $entry['user']['name'],
                        'password'  => Hash::make('password'),
                        'job_title' => $entry['job_title'] ?? null,
                    ]
                );

                if (!empty($entry['job_title'])) {
                    $user->update(['job_title' => $entry['job_title']]);
                }

                $client = Client::create([
                    'agency_id'       => $agency->id,
                    'company_name'    => $entry['company_name'],
                    'tier'            => $entry['tier'] ?? null,
                    'contact_user_id' => $user->id,
                ]);

                $this->command->info("✓ Created client: {$entry['company_name']}");
            } else {
                $client->update(['tier' => $entry['tier'] ?? $client->tier]);
                if (!empty($entry['job_title']) && $client->contact_user_id) {
                    User::where('id', $client->contact_user_id)->update([
                        'job_title' => $entry['job_title'],
                    ]);
                }
            }

            $projectData = $entry['project'];
            $exists = Project::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('name', $projectData['name'])
                ->exists();

            if (!$exists) {
                Project::create([
                    'agency_id'       => $agency->id,
                    'client_id'       => $client->id,
                    'name'            => $projectData['name'],
                    'type'            => $projectData['type'],
                    'status'          => $projectData['status'],
                    'budget'          => $projectData['budget'],
                    'estimated_hours' => $projectData['estimated_hours'],
                    'start_date'      => now()->subMonth(),
                    'end_date'        => now()->addMonths(2),
                    'color'           => $projectData['color'],
                ]);

                $this->command->info("✓ Created project: {$projectData['name']}");
            }
        }

        // Ensure Acme has a demo project too
        $acme = Client::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->where('company_name', 'Acme Corp')
            ->first();

        if ($acme && !Project::withoutGlobalScopes()
            ->where('agency_id', $agency->id)
            ->where('name', 'Acme Website Redesign')
            ->exists()) {
            Project::create([
                'agency_id'       => $agency->id,
                'client_id'       => $acme->id,
                'name'            => 'Acme Website Redesign',
                'type'            => 'web',
                'status'          => 'active',
                'budget'          => 12000,
                'estimated_hours' => 80,
                'start_date'      => now()->subMonths(2),
                'end_date'        => now()->addMonths(1),
                'color'           => '#802AEE',
            ]);
            $this->command->info('✓ Created project: Acme Website Redesign');
        }

        $this->call(DemoDataSeeder::class);
    }
}
