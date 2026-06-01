<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Client;
use App\Models\User;
use App\Services\DepartmentBootstrapService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $agency = Agency::create([
            'name'         => 'Sparkdraw Demo Agency',
            'logo_path'    => null,
            'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
            'domain_slug'  => 'demo',
        ]);

        User::create([
            'agency_id' => $agency->id,
            'role'      => 'admin',
            'name'      => 'Admin User',
            'email'     => 'admin@sparkdraw.test',
            'password'  => Hash::make('password'),
            'job_title' => 'Agency Admin',
            'department' => 'Management',
            'employment_type' => 'full_time',
            'availability' => 'offline',
        ]);

        $clientUser = User::create(['agency_id' => $agency->id, 'role' => 'client', 'name' => 'Client Contact', 'email' => 'client@sparkdraw.test', 'password' => Hash::make('password')]);

        Client::create(['agency_id' => $agency->id, 'company_name' => 'Acme Corp', 'contact_user_id' => $clientUser->id]);

        app(DepartmentBootstrapService::class)->ensureForAgency($agency->id);

        $this->call(DemoDataSeeder::class);
        $this->call(BulkDemoDataSeeder::class);
        $this->call(InsightsDemoSeeder::class);

        $this->command->info('✓ Agency: Sparkdraw Demo Agency');
        $this->command->info('✓ Users seeded — all passwords: password');
        $this->command->info('  admin@sparkdraw.test  (admin — visible in Team)');
        $this->command->info('  client@sparkdraw.test (client portal only)');
    }
}
