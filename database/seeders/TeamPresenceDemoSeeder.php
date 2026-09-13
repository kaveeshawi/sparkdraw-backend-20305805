<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Task;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Database\Seeder;

/**
 * Seeds a believable "live floor" for the Time tab:
 * open work sessions + availability for several demo agency users.
 */
class TeamPresenceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $agency = Agency::where('domain_slug', 'demo')->first();
        if (!$agency) {
            return;
        }

        $scenarios = [
            'admin@sparkdraw.test'  => ['availability' => 'busy',      'clocked_in' => true,  'hours_ago' => 3.2],
            'pm@sparkdraw.test'     => ['availability' => 'available', 'clocked_in' => true,  'hours_ago' => 2.5],
            'alex@sparkdraw.test'   => ['availability' => 'available', 'clocked_in' => true,  'hours_ago' => 4.0],
            'morgan@sparkdraw.test' => ['availability' => 'busy',      'clocked_in' => true,  'hours_ago' => 1.75],
            'sam@sparkdraw.test'    => ['availability' => 'away',      'clocked_in' => false, 'hours_ago' => null],
            'riley@sparkdraw.test'  => ['availability' => 'offline',   'clocked_in' => false, 'hours_ago' => null],
        ];

        foreach ($scenarios as $email => $cfg) {
            $user = User::where('email', $email)->where('agency_id', $agency->id)->first();
            if (!$user) {
                continue;
            }

            $user->update(['availability' => $cfg['availability']]);

            // Replace any open sessions so re-seed stays idempotent for today's floor
            WorkSession::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('agency_id', $agency->id)
                ->whereNull('clock_out_at')
                ->delete();

            if (!($cfg['clocked_in'] && $cfg['hours_ago'] !== null)) {
                continue;
            }

            WorkSession::withoutGlobalScopes()->create([
                'user_id'      => $user->id,
                'agency_id'    => $agency->id,
                'clock_in_at'  => now()->subHours($cfg['hours_ago'])->subMinutes(random_int(0, 40)),
                'clock_out_at' => null,
            ]);

            $existingToday = TimeLog::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('agency_id', $agency->id)
                ->whereDate('logged_date', now()->toDateString())
                ->exists();

            if ($existingToday) {
                continue;
            }

            $taskId = Task::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->where('assignee_id', $user->id)
                ->value('id');

            // Fall back to any agency task so admin still gets today hours
            if (!$taskId) {
                $taskId = Task::withoutGlobalScopes()
                    ->where('agency_id', $agency->id)
                    ->value('id');
            }

            if (!$taskId) {
                continue;
            }

            TimeLog::withoutGlobalScopes()->create([
                'agency_id'   => $agency->id,
                'task_id'     => $taskId,
                'user_id'     => $user->id,
                'hours'       => round($cfg['hours_ago'] * 0.85, 1),
                'logged_date' => now()->toDateString(),
                'notes'       => 'Demo presence seed',
            ]);
        }

        $this->command?->info('✓ Team presence demo: open sessions + availability for live Time floor');
    }
}
