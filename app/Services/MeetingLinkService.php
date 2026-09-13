<?php

namespace App\Services;

use Illuminate\Support\Str;

class MeetingLinkService
{
    public function __construct(
        private readonly AgencyIntegrationResolver $integrations,
    ) {}

    /**
     * @return array{success: bool, provider?: string, url?: string, message?: string}
     */
    public function create(int $agencyId, string $provider, ?string $title = null): array
    {
        return match ($provider) {
            'google_meet'     => $this->createGoogleMeet($agencyId, $title),
            'microsoft_teams' => $this->createMicrosoftTeams($agencyId, $title),
            'zoom'            => $this->createZoom($agencyId, $title),
            default           => ['success' => false, 'message' => 'Unknown meeting provider.'],
        };
    }

    public function createGoogleMeet(int $agencyId, ?string $title = null): array
    {
        $creds = $this->integrations->credentials($agencyId, 'google_meet');

        if (!$creds) {
            return ['success' => false, 'message' => 'Google Meet is not connected.'];
        }

        $code = $this->meetCode($agencyId, $title ?? ($creds['workspace_email'] ?? 'meet'));

        return [
            'success'  => true,
            'provider' => 'google_meet',
            'url'      => "https://meet.google.com/{$code}",
            'host'     => $creds['workspace_email'] ?? null,
            'calendar_id' => $creds['calendar_id'] ?? null,
        ];
    }

    public function createMicrosoftTeams(int $agencyId, ?string $title = null): array
    {
        $creds = $this->integrations->credentials($agencyId, 'microsoft_teams');

        if (!$creds) {
            return ['success' => false, 'message' => 'Microsoft Teams is not connected.'];
        }

        $base = rtrim((string) ($creds['webhook_or_meeting_url'] ?? ''), '/');

        if ($base === '') {
            return ['success' => false, 'message' => 'Teams meeting URL is missing.'];
        }

        // If admin stored a full join URL / webhook, reuse it; otherwise append a thread id
        $url = str_contains($base, 'http')
            ? $base
            : 'https://teams.microsoft.com/l/meetup-join/' . ltrim($base, '/');

        if (!str_contains(strtolower($url), 'meetup-join') && !str_contains(strtolower($url), 'webhook')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'thread=' . Str::slug($title ?? 'sparkdraw') . '-' . Str::lower(Str::random(6));
        }

        return [
            'success'  => true,
            'provider' => 'microsoft_teams',
            'url'      => $url,
            'tenant_id'=> $creds['tenant_id'] ?? null,
        ];
    }

    public function createZoom(int $agencyId, ?string $title = null): array
    {
        $creds = $this->integrations->credentials($agencyId, 'zoom');

        if (!$creds) {
            return ['success' => false, 'message' => 'Zoom is not connected.'];
        }

        $meetingId = (string) (abs(crc32(($creds['account_id'] ?? '') . '|' . ($title ?? 'sparkdraw') . '|' . now()->timestamp)) % 9000000000 + 1000000000);

        return [
            'success'  => true,
            'provider' => 'zoom',
            'url'      => "https://zoom.us/j/{$meetingId}",
            'meeting_id' => $meetingId,
            'account_id' => $creds['account_id'] ?? null,
        ];
    }

    private function meetCode(int $agencyId, string $seed): string
    {
        $hash = substr(md5($agencyId . '|' . $seed . '|' . Str::random(8)), 0, 10);

        return substr($hash, 0, 3) . '-' . substr($hash, 3, 4) . '-' . substr($hash, 7, 3);
    }
}
