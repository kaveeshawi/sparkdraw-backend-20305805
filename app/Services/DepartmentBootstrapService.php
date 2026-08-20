<?php

namespace App\Services;

use App\Models\Department;

class DepartmentBootstrapService
{
    public const DEFAULT_NAME = 'Management';

    public function ensureForAgency(int $agencyId): Department
    {
        return Department::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId, 'name' => self::DEFAULT_NAME],
            ['name' => self::DEFAULT_NAME, 'agency_id' => $agencyId],
        );
    }

    public function ensureForAllAgencies(): int
    {
        $count = 0;
        foreach (\App\Models\Agency::query()->pluck('id') as $agencyId) {
            $this->ensureForAgency($agencyId);
            $count++;
        }

        return $count;
    }
}
