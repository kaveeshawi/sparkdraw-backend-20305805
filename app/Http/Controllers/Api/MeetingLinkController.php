<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\MeetingLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeetingLinkController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MeetingLinkService $meetings) {}

    // POST /api/v1/meetings
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(['google_meet', 'microsoft_teams', 'zoom'])],
            'title'    => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->meetings->create(
            $request->user()->agency_id,
            $validated['provider'],
            $validated['title'] ?? null
        );

        if (empty($result['success'])) {
            return $this->error($result['message'] ?? 'Could not create meeting link.', [], 422);
        }

        return $this->success($result, 'Meeting link created.');
    }
}
