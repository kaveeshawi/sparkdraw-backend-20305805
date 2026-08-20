<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\AgencyIntegration;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AgencyMailer
{
    public function send(Agency $agency, Mailable $mailable, string $to): void
    {
        $integration = AgencyIntegration::where('agency_id', $agency->id)
            ->where('provider', 'mail_smtp')
            ->where('status', 'connected')
            ->first();

        if (!$integration || empty($integration->credentials)) {
            Log::warning('Agency has no connected mail_smtp integration; using default mailer.', [
                'agency_id' => $agency->id,
            ]);

            Mail::to($to)->send($mailable);

            return;
        }

        $credentials = $integration->credentials;
        $mailerName  = 'agency_smtp_' . $agency->id;

        config([
            "mail.mailers.{$mailerName}" => [
                'transport'  => 'smtp',
                'host'       => $credentials['host'],
                'port'       => $credentials['port'],
                'encryption' => $credentials['encryption'] ?? null,
                'username'   => $credentials['username'],
                'password'   => $credentials['password'],
                'timeout'    => null,
            ],
        ]);

        if (!empty($credentials['from_address'])) {
            $mailable->from(
                $credentials['from_address'],
                $credentials['from_name'] ?? null
            );
        }

        Mail::mailer($mailerName)->to($to)->send($mailable);
    }
}
