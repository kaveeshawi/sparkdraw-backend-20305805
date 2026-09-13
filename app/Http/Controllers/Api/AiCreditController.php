<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\AiCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiCreditController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AiCreditService $credits) {}

    // GET /api/v1/ai/credits
    public function index(Request $request): JsonResponse
    {
        return $this->success($this->credits->summary($request->user()));
    }
}
