<?php

namespace App\Services;

use App\Models\AiCreditUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiCreditService
{
    public function periodStart(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfMonth();
    }

    public function periodEnd(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->endOfMonth();
    }

    public function allowanceFor(User $user): int
    {
        $role = $user->role ?: 'member';
        $map = config('ai_credits.allowances', []);

        return (int) ($map[$role] ?? $map['member'] ?? 1000);
    }

    public function costFor(string $feature): int
    {
        $costs = config('ai_credits.costs', []);

        if (!array_key_exists($feature, $costs)) {
            throw new RuntimeException("Unknown AI credit feature: {$feature}");
        }

        return (int) $costs[$feature];
    }

    public function usedThisPeriod(User $user): int
    {
        return (int) AiCreditUsage::withoutGlobalScope('agency')
            ->where('agency_id', $user->agency_id)
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [
                $this->periodStart()->toDateTimeString(),
                $this->periodEnd()->toDateTimeString(),
            ])
            ->sum('credits');
    }

    public function remaining(User $user): int
    {
        return max(0, $this->allowanceFor($user) - $this->usedThisPeriod($user));
    }

    public function canAfford(User $user, string $feature): bool
    {
        return $this->remaining($user) >= $this->costFor($feature);
    }

    /**
     * Deduct credits after a successful AI call.
     *
     * @throws RuntimeException when balance is insufficient
     */
    public function charge(User $user, string $feature, array $metadata = []): AiCreditUsage
    {
        $cost = $this->costFor($feature);

        return DB::transaction(function () use ($user, $feature, $cost, $metadata) {
            $used = (int) AiCreditUsage::withoutGlobalScope('agency')
                ->where('agency_id', $user->agency_id)
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [
                    $this->periodStart()->toDateTimeString(),
                    $this->periodEnd()->toDateTimeString(),
                ])
                ->lockForUpdate()
                ->sum('credits');

            $allowance = $this->allowanceFor($user);
            $remaining = max(0, $allowance - $used);

            if ($remaining < $cost) {
                throw new RuntimeException(
                    "Not enough AI credits. This action costs {$cost} credit(s); you have {$remaining} left this month."
                );
            }

            return AiCreditUsage::create([
                'agency_id' => $user->agency_id,
                'user_id'   => $user->id,
                'feature'   => $feature,
                'credits'   => $cost,
                'metadata'  => $metadata ?: null,
            ]);
        });
    }

    public function featureCatalog(): array
    {
        $costs = config('ai_credits.costs', []);
        $labels = config('ai_credits.labels', []);

        return collect($costs)->map(function (int $cost, string $key) use ($labels) {
            return [
                'feature' => $key,
                'label'   => $labels[$key] ?? $key,
                'cost'    => $cost,
            ];
        })->values()->all();
    }

    public function summary(User $user): array
    {
        $allowance = $this->allowanceFor($user);
        $used = $this->usedThisPeriod($user);
        $remaining = max(0, $allowance - $used);

        return [
            'plan_name' => config('ai_credits.plan_name', 'Agency Starter'),
            'role'      => $user->role,
            'period'    => [
                'start' => $this->periodStart()->toDateString(),
                'end'   => $this->periodEnd()->toDateString(),
            ],
            'allowance' => $allowance,
            'used'      => $used,
            'remaining' => $remaining,
            'features'  => $this->featureCatalog(),
            'allowances_by_role' => [
                'admin'  => (int) config('ai_credits.allowances.admin', 1500),
                'pm'     => (int) config('ai_credits.allowances.pm', 1000),
                'member' => (int) config('ai_credits.allowances.member', 1000),
            ],
        ];
    }
}
