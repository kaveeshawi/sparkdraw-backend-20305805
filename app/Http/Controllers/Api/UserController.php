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
use App\Models\WorkSession;
use App\Services\AgencyMailer;
use App\Services\PasswordSetupService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\Rule;

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
        $this->ensureAccessRevokedColumn();

        $team = User::where('role', '!=', 'client')
            ->with(['passwordSetupTokens', 'customRole'])
            ->select(
                'id',
                'name',
                'email',
                'role',
                'custom_role_id',
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

        $temporaryPassword = $this->generateTemporaryPassword();

        $user = User::create([
            'agency_id'       => $inviter->agency_id,
            'role'            => $request->role,
            'custom_role_id'  => $request->input('custom_role_id'),
            'name'            => $request->name,
            'email'           => $request->email,
            'password'        => $temporaryPassword,
            'department'      => $request->department,
            'employment_type' => $request->employment_type,
            'availability'    => 'offline',
            'phone'           => $request->phone,
            'job_title'       => $request->job_title,
            'profile_meta'    => $profileMeta ?: null,
            'access_revoked_at' => null,
        ]);

        // Mark login-ready (no set-password link required)
        $this->markCredentialsProvisioned($user);

        if ($request->boolean('send_email', false)) {
            try {
                $this->deliverCredentialsEmail($user, $agency, $temporaryPassword);
            } catch (\Throwable $e) {
                Log::warning('Team credentials email failed after user creation', [
                    'user_id'   => $user->id,
                    'agency_id' => $agency->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $user->load(['passwordSetupTokens', 'customRole']);

        ProjectEvent::log($inviter->agency_id, null, 'user_invited', [
            'invited_user_id'    => $user->id,
            'invited_user_email' => $user->email,
            'role'               => $user->role,
            'invited_by'         => $inviter->id,
            'provisioning'       => 'credentials',
        ]);

        return $this->created(
            array_merge($this->memberPayload($user), [
                'temporary_password' => $temporaryPassword,
            ]),
            'User invited successfully.',
        );
    }

    // GET /api/v1/team/{user} — admin/pm any member; member can only view self
    public function show(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        $actor = $request->user();
        if (!in_array($actor->role, ['admin', 'pm'], true) && (int) $actor->id !== (int) $user->id) {
            return $this->forbidden('You can only view your own team portal.');
        }

        $user->load(['passwordSetupTokens', 'customRole']);

        return $this->success($this->memberPayload($user));
    }

    // POST /api/v1/team/{user}/resend-invite  (admin only — enforced at route level)
    public function resendInvite(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        $agency = Agency::findOrFail($request->user()->agency_id);
        $sendEmail = $request->boolean('send_email', true);

        $result = RateLimiter::attempt(
            'resend-invite:' . $user->id,
            1,
            function () use ($user, $agency, $sendEmail) {
                $temporaryPassword = $this->generateTemporaryPassword();
                $user->update([
                    'password' => $temporaryPassword,
                    'access_revoked_at' => null,
                ]);
                $this->markCredentialsProvisioned($user);

                if ($sendEmail) {
                    $this->deliverCredentialsEmail($user, $agency, $temporaryPassword);
                }

                return $temporaryPassword;
            },
            60,
        );

        if ($result === false) {
            return $this->error(
                'Too many resend attempts. Please wait before trying again.',
                [],
                429,
            );
        }

        return $this->success(
            [
                'email' => $user->email,
                'temporary_password' => $result,
            ],
            $sendEmail ? 'Credentials resent successfully.' : 'New login credentials generated.',
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

        // A role change invalidates a custom role tied to the old tier unless the
        // caller explicitly picked a new one in the same request.
        if (isset($validated['role']) && $validated['role'] !== $previousRole && !array_key_exists('custom_role_id', $validated)) {
            $validated['custom_role_id'] = null;
        }

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
            $this->memberPayload($user->fresh(['customRole'])),
            'User updated successfully.',
        );
    }

    // PATCH /api/v1/team/{user}/availability  (admin only — no clock-in required)
    public function updateAvailability(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        $validated = $request->validate([
            'availability' => ['required', Rule::in(['available', 'busy', 'away', 'offline'])],
        ]);

        $availability = $validated['availability'];

        DB::transaction(function () use ($user, $availability) {
            $user->update(['availability' => $availability]);

            // Offline means leave duty — close any open work session
            if ($availability === 'offline') {
                WorkSession::withoutGlobalScope('agency')
                    ->where('agency_id', $user->agency_id)
                    ->where('user_id', $user->id)
                    ->whereNull('clock_out_at')
                    ->update(['clock_out_at' => now()]);
            }
        });

        ProjectEvent::log($request->user()->agency_id, null, 'user_availability_updated', [
            'user_id'      => $user->id,
            'availability' => $availability,
            'updated_by'   => $request->user()->id,
        ]);

        $user->refresh();
        $openSession = WorkSession::withoutGlobalScope('agency')
            ->where('agency_id', $user->agency_id)
            ->where('user_id', $user->id)
            ->whereNull('clock_out_at')
            ->first();

        return $this->success([
            'user_id'       => $user->id,
            'availability'  => $user->availability,
            'is_clocked_in' => $openSession !== null,
            'clock_in_at'   => $openSession?->clock_in_at?->toIso8601String(),
        ], 'Availability updated.');
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
    // Hard-delete kept for cleanup; portal "Revoke access" uses revokeAccess().
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

    // POST /api/v1/team/{user}/revoke-access  (admin only)
    // Keeps the member on the roster; blocks login and clears tokens.
    public function revokeAccess(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        if ($user->role === 'admin') {
            return $this->error('Agency admin access cannot be revoked.', [], 422);
        }

        try {
            $this->ensureAccessRevokedColumn();

            if ($user->isAccessRevoked()) {
                return $this->success(
                    $this->memberPayload($user->fresh(['customRole', 'passwordSetupTokens'])),
                    'Access is already revoked.',
                );
            }

            $user->update(['access_revoked_at' => now()]);
            $user->tokens()->delete();

            ProjectEvent::log($request->user()->agency_id, null, 'user_access_revoked', [
                'user_id'    => $user->id,
                'revoked_by' => $request->user()->id,
            ]);

            return $this->success(
                $this->memberPayload($user->fresh(['customRole', 'passwordSetupTokens'])),
                'Member access revoked.',
            );
        } catch (QueryException $e) {
            Log::error('Failed to revoke team member access', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->error('Could not revoke access right now. Please try again.', [], 500);
        }
    }

    // POST /api/v1/team/{user}/restore-access  (admin only)
    public function restoreAccess(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'client') {
            return $this->notFound();
        }

        if ($user->role === 'admin') {
            return $this->error('Agency admin access cannot be changed this way.', [], 422);
        }

        try {
            $this->ensureAccessRevokedColumn();

            if (!$user->isAccessRevoked()) {
                return $this->success(
                    $this->memberPayload($user->fresh(['customRole', 'passwordSetupTokens'])),
                    'Access is already active.',
                );
            }

            $user->update(['access_revoked_at' => null]);

            ProjectEvent::log($request->user()->agency_id, null, 'user_access_restored', [
                'user_id'     => $user->id,
                'restored_by' => $request->user()->id,
            ]);

            return $this->success(
                $this->memberPayload($user->fresh(['customRole', 'passwordSetupTokens'])),
                'Member access restored.',
            );
        } catch (QueryException $e) {
            Log::error('Failed to restore team member access', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->error('Could not restore access right now. Please try again.', [], 500);
        }
    }

    private function ensureAccessRevokedColumn(): void
    {
        if (Schema::hasColumn('users', 'access_revoked_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('access_revoked_at')->nullable()->after('avatar_path');
        });
    }

    private function generateTemporaryPassword(): string
    {
        // Readable for admin to copy/share — excludes ambiguous characters
        return strtoupper(Str::password(10, true, true, false));
    }

    private function markCredentialsProvisioned(User $user): void
    {
        PasswordSetupToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'expires_at' => now()]);

        PasswordSetupToken::create([
            'user_id'    => $user->id,
            'token'      => $this->passwordSetupService->hashToken(Str::random(64)),
            'expires_at' => now(),
            'used_at'    => now(),
        ]);
    }

    private function deliverCredentialsEmail(User $user, Agency $agency, string $temporaryPassword): void
    {
        $this->agencyMailer->send(
            $agency,
            new TeamInviteMail($user, loginUrl: $this->buildLoginUrl(), temporaryPassword: $temporaryPassword),
            $user->email,
        );
    }

    private function buildLoginUrl(): string
    {
        $base = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return $base . '/login';
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
            'custom_role_id'  => $user->custom_role_id,
            'custom_role_name' => $user->relationLoaded('customRole') ? $user->customRole?->name : null,
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
            'access_revoked'  => $user->isAccessRevoked(),
            'access_revoked_at' => $user->access_revoked_at,
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

        if ($user->isAccessRevoked()) {
            return 'access_revoked';
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
