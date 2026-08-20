<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentBootstrapService;
use Illuminate\Database\Seeder;

class TeamResetSeeder extends Seeder
{
    /**
     * Remove all non-admin team members. Keeps admin + client portal users.
     */
    public function run(): void
    {
        $removed = User::query()
            ->whereIn('role', ['pm', 'member'])
            ->delete();

        $this->command?->info("Removed {$removed} team member(s). Agency admin(s) retained.");

        User::query()
            ->where('role', 'admin')
            ->where(function ($q) {
                $q->whereNull('department')->orWhere('department', '');
            })
            ->update(['department' => DepartmentBootstrapService::DEFAULT_NAME]);

        $bootstrap = app(DepartmentBootstrapService::class);

        Agency::query()->pluck('id')->each(function (int $agencyId) use ($bootstrap) {
            $bootstrap->ensureForAgency($agencyId);

            Department::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('name', '!=', DepartmentBootstrapService::DEFAULT_NAME)
                ->get()
                ->each(function (Department $dept) use ($agencyId) {
                    $assigned = User::withoutGlobalScopes()
                        ->where('agency_id', $agencyId)
                        ->where('role', '!=', 'client')
                        ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($dept->name))])
                        ->exists();

                    if (!$assigned) {
                        $dept->delete();
                    }
                });
        });

        $this->command?->info('Ensured default Management department for all agencies.');
    }
}
