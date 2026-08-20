<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\WorkSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkSessionController extends Controller
{
    use ApiResponse;

    // GET /api/v1/work-sessions/current
    public function current(Request $request): JsonResponse
    {
        $session = WorkSession::where('user_id', $request->user()->id)
            ->whereNull('clock_out_at')
            ->first();

        return $this->success($session ? $this->formatSession($session) : null);
    }

    // POST /api/v1/work-sessions/clock-in
    public function clockIn(Request $request): JsonResponse
    {
        $user = $request->user();

        $openSession = WorkSession::where('user_id', $user->id)
            ->whereNull('clock_out_at')
            ->exists();

        if ($openSession) {
            return $this->error('You already have an open work session.', [], 422);
        }

        $session = DB::transaction(function () use ($user) {
            $session = WorkSession::create([
                'user_id'     => $user->id,
                'agency_id'   => $user->agency_id,
                'clock_in_at' => now(),
            ]);

            $user->update(['availability' => 'available']);

            return $session;
        });

        return $this->success([
            'session'      => $this->formatSession($session),
            'availability' => 'available',
        ]);
    }

    // POST /api/v1/work-sessions/clock-out
    public function clockOut(Request $request): JsonResponse
    {
        $user = $request->user();

        $session = WorkSession::where('user_id', $user->id)
            ->whereNull('clock_out_at')
            ->first();

        if (!$session) {
            return $this->error('No open work session to clock out of.', [], 422);
        }

        DB::transaction(function () use ($user, $session) {
            $session->update(['clock_out_at' => now()]);
            $user->update(['availability' => 'offline']);
        });

        $session->refresh();

        return $this->success([
            'session'      => $this->formatSession($session),
            'availability' => 'offline',
        ]);
    }

    private function formatSession(WorkSession $session): array
    {
        return [
            'id'           => $session->id,
            'clock_in_at'  => $session->clock_in_at,
            'clock_out_at' => $session->clock_out_at,
        ];
    }
}
