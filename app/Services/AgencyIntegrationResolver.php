<?php

namespace App\Services;

use App\Models\AgencyIntegration;
use Illuminate\Support\Collection;

class AgencyIntegrationResolver
{
    public function find(int $agencyId, string $provider): ?AgencyIntegration
    {
        return AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->where('provider', $provider)
            ->where('status', 'connected')
            ->first();
    }

    public function credentials(int $agencyId, string $provider): ?array
    {
        $integration = $this->find($agencyId, $provider);

        if (!$integration || empty($integration->credentials)) {
            return null;
        }

        return $integration->credentials;
    }

    public function isConnected(int $agencyId, string $provider): bool
    {
        return $this->credentials($agencyId, $provider) !== null;
    }

    /**
     * @param  list<string>  $providers
     * @return list<string>
     */
    public function connectedProviders(int $agencyId, array $providers): array
    {
        if ($providers === []) {
            return [];
        }

        return AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->whereIn('provider', $providers)
            ->where('status', 'connected')
            ->whereNotNull('credentials')
            ->pluck('provider')
            ->values()
            ->all();
    }

    public function connectedMap(int $agencyId, array $providers): Collection
    {
        return AgencyIntegration::withoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->whereIn('provider', $providers)
            ->where('status', 'connected')
            ->get()
            ->keyBy('provider');
    }
}
