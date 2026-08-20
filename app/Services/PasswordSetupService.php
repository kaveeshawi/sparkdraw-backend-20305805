<?php

namespace App\Services;

use App\Models\PasswordSetupToken;
use App\Models\User;
use Illuminate\Support\Str;

class PasswordSetupService
{
    public function issueToken(User $user): string
    {
        PasswordSetupToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $plain = Str::random(64);

        PasswordSetupToken::create([
            'user_id'    => $user->id,
            'token'      => $this->hashToken($plain),
            'expires_at' => now()->addHours(48),
        ]);

        return $plain;
    }

    public function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function findValidToken(string $plain): ?PasswordSetupToken
    {
        $token = PasswordSetupToken::where('token', $this->hashToken($plain))
            ->with('user')
            ->first();

        if (!$token || !$token->isValid()) {
            return null;
        }

        return $token;
    }
}
