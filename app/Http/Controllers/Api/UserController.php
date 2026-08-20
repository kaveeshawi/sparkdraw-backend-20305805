<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\InviteUserRequest;
use App\Http\Requests\Team\UpdateTeamMemberRequest;
use App\Http\Traits\ApiResponse;
use App\Mail\TeamInviteMail;
use App\Models\Agency;
use App\Models\PasswordSetupToken;
use App\Models\ProjectEvent;
use App\Models\User;
use App\Services\AgencyMailer;
use App\Services\PasswordSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PasswordSetupService $passwordSetupService,
        private readonly AgencyMailer $agencyMailer,
    ) {}

    // GET /api/v1/team  — list team members (excludes client-role users)
    public function index(Request $request): JsonResponse
    {
        $team = User::where('role', '!=', 'client')
            ->with('passwordSetupTokens')
            ->select(
                'id',
                'name',
                'email',
                'role',
                'department',
                'employment_type',
                'availability',
                'phone',
                'job_title',
                'avatar_path',
                'profile_meta',
                'created_at',
                'updated_at',
            )
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->memberPayload($user));

        return $this->success($team);
    }

    // POST /api/v1/team/invite  (admin only — enforced at route level)
    public function invite(InviteUserRequest $request): JsonResponse
    {
        $inviter = $request->user();
        $agency = Agency::findOrFail($inviter->agency_id);

        $profileMeta = array_filter([
            'employee_id'   => $request->input('employee_id'),
            'phone_country' => $request->input('phone_country'),
            'first_name'    => $request->input('first_name'),
            'last_name'     => $request->input('last_name'),
            'address'       => $request->input('address'),
            'birthday'      => $request->input('birthday'),
            'gender'        => $request->input('gender'),
            'start_date'    => $request->input('start_date'),
            'work_location' => $request->input('work_location'),
        ], fn ($v) => $v !== null && $v !== '');

        $user = User::create([
            'agency_id'       => $inviter->agency_id,
            'role'            => $request->role,
            'name'            => $request->name,
            'email'           => $request->email,
            'password'        => Hash::make(str()->random(32)),
            'department'      => $request->department,
            'employment_type' => $request->employment_type,
            'availability'    => 'offline',
            'phone'           => $request->phone,
            'job_title'       => $request->job_title,
            'profile_meta'    => $profileMeta ?: null,
        ]);

        $inviteUrl = null;
        if ($request->boolean('send_email', false)) {
            try {
                $inviteUrl = $this->sendInvite($user, $agency);
            } catch (\Throwable $e) {
                Log::warning('Team invite email failed after user creation', [
                    'user_id'   => $user->id,
                    'agency_id' => $agency->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $user->load('passwordSetupTokens');

        ProjectEvent::log($inviter->agency_id, null, 'user_invited', [
            'invited_user_id'    => $user->id,
            'invited_user_email' => $user->email,
            'role'               => $user->role,
            'invited_by'         => $inviter->id,
        ]);

        return $this->created(
            $this->memberPayload($user, $inviteUrl),
            'User invited successfully.',
        );
    }

    // POST /api/v1/team/{user}/resend-invite  (admin only — enforced at route level)
    public function resendInvite(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        $agency = Agency::findOrFail($request->user()->agency_id);

        $inviteUrl = RateLimiter::attempt(
            'resend-invite:' . $user->id,
            1,
            fn () => $this->sendInvite($user, $agency),
            60,
        );

        if ($inviteUrl === false) {
            return $this->error(
                'Too many resend attempts. Please wait before trying again.',
                [],
                429,
            );
        }

        return $this->success(
            ['invite_url' => $inviteUrl],
            'Invite resent successfully.',
        );
    }

    // PUT /api/v1/team/{user}  (admin only — enforced at route level)
    public function update(UpdateTeamMemberRequest $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        $validated = $request->validated();

        if ($user->role === 'admin') {
            $validated['department'] = 'Management';
            if (isset($validated['profile_meta'])) {
                unset($validated['profile_meta']['employee_id']);
            }
        }

        if (isset($validated['profile_meta'])) {
            $validated['profile_meta'] = array_merge(
                $user->profile_meta ?? [],
                $validated['profile_meta'],
            );
        }

        $previousRole = $user->role;
        $user->update($validated);

        if (isset($validated['role']) && $validated['role'] !== $previousRole) {
            ProjectEvent::log($request->user()->agency_id, null, 'user_role_changed', [
                'user_id'       => $user->id,
                'previous_role' => $previousRole,
                'new_role'      => $user->role,
                'changed_by'    => $request->user()->id,
            ]);
        }

        ProjectEvent::log($request->user()->agency_id, null, 'user_profile_updated', [
            'user_id'    => $user->id,
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            $this->memberPayload($user->fresh()),
            'User updated successfully.',
        );
    }

    // POST /api/v1/team/{user}/avatar  (admin only — enforced at route level)
    public function uploadAvatar(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store(
            "agencies/{$request->user()->agency_id}/avatars",
            'public',
        );

        $user->update(['avatar_path' => $path]);

        ProjectEvent::log($request->user()->agency_id, null, 'user_avatar_updated', [
            'user_id'    => $user->id,
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            $this->memberPayload($user->fresh()),
            'Profile photo updated.',
        );
    }

    // DELETE /api/v1/team/{user}  (admin only — enforced at route level)
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        if ($user->role === 'admin') {
            return $this->error('Agency admin accounts cannot be removed from the team.', [], 422);
        }

        $user->delete();

        ProjectEvent::log($request->user()->agency_id, null, 'user_removed', [
            'removed_user_id' => $user->id,
            'removed_by'      => $request->user()->id,
        ]);

        return $this->success(message: 'User removed from agency.');
    }

    private function sendInvite(User $user, Agency $agency): string
    {
        $plain = $this->passwordSetupService->issueToken($user);
        $inviteUrl = $this->buildInviteUrl($plain);

        $this->agencyMailer->send(
            $agency,
            new TeamInviteMail($user, $inviteUrl),
            $user->email,
        );

        return $inviteUrl;
    }

    private function buildInviteUrl(string $plain): string
    {
        $base = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return $base . '/set-password?token=' . $plain;
    }

    private function memberPayload(User $user, ?string $inviteUrl = null): array
    {
        $meta = $user->profile_meta ?? [];
        $isAdmin = $user->role === 'admin';
        $department = trim((string) ($user->department ?? ''));
        if ($isAdmin && $department === '') {
            $department = 'Management';
        }

        return [
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'role'            => $user->role,
            'department'      => $department ?: $user->department,
            'employment_type' => $user->employment_type,
            'availability'    => $user->availability,
            'phone'           => $user->phone,
            'job_title'       => $user->job_title,
            'avatar_url'      => $this->avatarUrl($user->avatar_path),
            'avatar_path'     => $user->avatar_path,
            'profile_meta'    => $meta,
            'first_name'      => $meta['first_name'] ?? null,
            'last_name'       => $meta['last_name'] ?? null,
            'address'         => $meta['address'] ?? null,
            'birthday'        => $meta['birthday'] ?? null,
            'gender'          => $meta['gender'] ?? null,
            'start_date'      => $meta['start_date'] ?? null,
            'work_location'   => $meta['work_location'] ?? null,
            'employee_id'     => $isAdmin ? null : ($meta['employee_id'] ?? null),
            'phone_country'   => $meta['phone_country'] ?? null,
            'is_protected'    => $isAdmin,
            'invite_status'   => $this->inviteStatus($user),
            'created_at'      => $user->created_at,
            'updated_at'      => $user->updated_at,
            'invite_url'      => $inviteUrl,
        ];
    }

    private function inviteStatus(User $user): string
    {
        if ($user->role === 'admin') {
            return 'active';
        }

        $tokens = $user->relationLoaded('passwordSetupTokens')
            ? $user->passwordSetupTokens
            : $user->passwordSetupTokens()->get();

        if ($tokens->contains(fn (PasswordSetupToken $token) => $token->used_at !== null)) {
            return 'active';
        }

        if (DB::table('sessions')->where('user_id', $user->id)->exists()) {
            return 'active';
        }

        if ($tokens->contains(fn (PasswordSetupToken $token) => $token->isValid())) {
            return 'invite_pending';
        }

        if ($tokens->isNotEmpty()) {
            return 'invite_expired';
        }

        return 'invite_not_sent';
    }

    private function avatarUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return rtrim(config('app.url'), '/') . Storage::disk('public')->url($path);
    }
}
