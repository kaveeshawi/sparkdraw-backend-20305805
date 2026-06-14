<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Agency;
use App\Models\User;
use App\Services\DepartmentBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    // POST /api/v1/auth/register
    // Creates a new agency and its first admin user in a single atomic transaction.
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = DB::transaction(function () use ($request) {
            $agency = Agency::create([
                'name'         => $request->agency_name,
                'domain_slug'  => Str::slug($request->agency_name) . '-' . Str::random(5),
                'brand_colors' => ['primary' => '#802AEE', 'light' => '#f3e8ff'],
            ]);

            $user = User::create([
                'agency_id' => $agency->id,
                'role'      => 'admin',
                'name'      => $request->name,
                'email'     => $request->email,
                'password'  => Hash::make($request->password),
                'department' => DepartmentBootstrapService::DEFAULT_NAME,
            ]);

            app(DepartmentBootstrapService::class)->ensureForAgency($agency->id);

            $token = $user->createToken('api-token')->plainTextToken;

            return compact('agency', 'user', 'token');
        });

        return $this->created([
            'token' => $result['token'],
            'user'  => $this->formatUser($result['user'], $result['agency']),
        ], 'Agency and admin account created successfully.');
    }

    // POST /api/v1/auth/login
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::withoutAgencyScope()->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ])->status(401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user'  => $this->formatUser($user, $user->agency),
        ], 'Login successful.');
    }

    // POST /api/v1/auth/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(message: 'Logged out successfully.');
    }

    // GET /api/v1/auth/me
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('agency');

        return $this->success($this->formatUser($user, $user->agency));
    }

    private function formatUser(User $user, ?Agency $agency): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'role'     => $user->role,
            'agency_id' => $user->agency_id,
            'agency'   => $agency ? [
                'id'           => $agency->id,
                'name'         => $agency->name,
                'domain_slug'  => $agency->domain_slug,
                'brand_colors' => $agency->brand_colors,
                'logo_path'    => $agency->logo_path,
            ] : null,
        ];
    }
}
