<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeAvailabilityController extends Controller
{
    use ApiResponse;

    // PATCH /api/v1/me/availability
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'availability' => ['required', Rule::in(['away', 'busy', 'available'])],
        ]);

        $user = $request->user();

        $hasOpenSession = WorkSession::where('user_id', $user->id)
            ->whereNull('clock_out_at')
            ->exists();

        if (!$hasOpenSession) {
            return $this->error('You must be clocked in to change availability.', [], 422);
        }

        $user->update(['availability' => $validated['availability']]);

        return $this->success([
            'availability' => $user->availability,
        ]);
    }
}
