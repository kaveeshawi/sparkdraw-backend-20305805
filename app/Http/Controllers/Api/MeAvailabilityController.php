<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeAvailabilityController extends Controller
{
    use ApiResponse;

    // PATCH /api/v1/me/availability
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $validated = $request->validate([
            'availability' => [
                'required',
                Rule::in($isAdmin
                    ? ['available', 'busy', 'away', 'offline']
                    : ['available', 'busy', 'away']),
            ],
        ]);

        $availability = $validated['availability'];

        // Non-admins must be clocked in to change status.
        // Admins set status directly (no clock-in required).
        if (!$isAdmin) {
            $hasOpenSession = WorkSession::where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->exists();

            if (!$hasOpenSession) {
                return $this->error('You must be clocked in to change availability.', [], 422);
            }
        }

        DB::transaction(function () use ($user, $availability, $isAdmin) {
            $user->update(['availability' => $availability]);

            if ($isAdmin && $availability === 'offline') {
                WorkSession::where('user_id', $user->id)
                    ->whereNull('clock_out_at')
                    ->update(['clock_out_at' => now()]);
            }
        });

        return $this->success([
            'availability' => $user->fresh()->availability,
        ]);
    }
}
