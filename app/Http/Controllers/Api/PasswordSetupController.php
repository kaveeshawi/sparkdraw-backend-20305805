<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\PasswordSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PasswordSetupController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PasswordSetupService $passwordSetupService) {}

    // GET /api/v1/password-setup/validate?token=
    public function validate(Request $request): JsonResponse
    {
        $plain = $request->query('token');

        if (!$plain) {
            return $this->tokenGone('Token is required.');
        }

        $token = $this->passwordSetupService->findValidToken($plain);

        if (!$token) {
            return $this->tokenGone('This password setup link is invalid or has expired.');
        }

        return $this->success([
            'email' => $token->user->email,
            'name'  => $token->user->name,
        ]);
    }

    // POST /api/v1/password-setup
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'                 => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $token = $this->passwordSetupService->findValidToken($validated['token']);

        if (!$token) {
            return $this->tokenGone('This password setup link is invalid or has expired.');
        }

        DB::transaction(function () use ($token, $validated) {
            $token->user->update(['password' => $validated['password']]);
            $token->update(['used_at' => now()]);
        });

        return $this->success(null, 'Password set successfully.');
    }

    private function tokenGone(string $message): JsonResponse
    {
        return $this->error($message, [], 410);
    }
}
