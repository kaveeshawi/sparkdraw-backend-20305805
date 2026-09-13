<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Http\Traits\ApiResponse;
use App\Mail\ClientInviteMail;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Message;
use App\Models\PasswordSetupToken;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Models\User;
use Carbon\Carbon;
use App\Services\AgencyMailer;
use App\Services\PasswordSetupService;
use App\Services\SentimentService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class ClientController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PasswordSetupService $passwordSetupService,
        private readonly AgencyMailer $agencyMailer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        $query = Client::query()
            ->visibleTo($viewer)
            ->with([
                'agency:id,domain_slug',
                'contactUser:id,name,email,phone,job_title,avatar_path,profile_meta,updated_at',
                'contactUser.passwordSetupTokens',
            ])
            ->withCount([
                'projects',
                'projects as active_projects_count' => fn ($q) => $q->where('status', '!=', 'completed'),
                'invoices as open_invoices_count' => fn ($q) => $q->whereIn('status', ['sent', 'overdue']),
            ]);

        $tier = Client::normalizeTier($request->query('tier'));
        if ($tier !== null) {
            $query->tier($tier);
        }

        $clients = $query
            ->orderBy('company_name')
            ->get()
            ->map(fn (Client $client) => $this->clientPayload($client, $viewer));

        return $this->success($clients);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        if (!$client->isVisibleTo($request->user())) {
            return $this->notFound('Client not found.');
        }

        $client->loadCount([
            'projects',
            'projects as active_projects_count' => fn ($q) => $q->where('status', '!=', 'completed'),
            'invoices as open_invoices_count' => fn ($q) => $q->whereIn('status', ['sent', 'overdue']),
        ]);
        $client->load([
            'agency:id,domain_slug',
            'contactUser:id,name,email,phone,job_title,avatar_path,profile_meta,updated_at',
            'contactUser.passwordSetupTokens',
        ]);

        return $this->success($this->clientPayload($client, $request->user()));
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $inviter = $request->user();
        $agency = Agency::findOrFail($inviter->agency_id);

        $client = DB::transaction(function () use ($request, $inviter) {
            $meta = array_filter([
                'first_name'    => $request->input('first_name'),
                'last_name'     => $request->input('last_name'),
                'address'       => $request->input('address'),
                'phone_country' => $request->input('phone_country'),
            ], fn ($v) => $v !== null && $v !== '');

            $contact = User::create([
                'agency_id'    => $inviter->agency_id,
                'role'         => 'client',
                'name'         => $request->contact_name,
                'email'        => $request->contact_email,
                'password'     => Hash::make(str()->random(32)),
                'job_title'    => $request->filled('job_title') ? trim((string) $request->job_title) : null,
                'phone'        => $request->filled('phone') ? trim((string) $request->phone) : null,
                'profile_meta' => $meta ?: null,
            ]);

            return Client::create([
                'agency_id'       => $inviter->agency_id,
                'company_name'    => trim($request->company_name),
                'contact_user_id' => $contact->id,
                'tier'            => Client::normalizeTier($request->input('tier')),
            ]);
        });

        $client->load([
            'agency:id,domain_slug',
            'contactUser:id,name,email,phone,job_title,avatar_path,profile_meta,updated_at',
            'contactUser.passwordSetupTokens',
        ]);
        $client->loadCount([
            'projects',
            'projects as active_projects_count' => fn ($q) => $q->where('status', '!=', 'completed'),
            'invoices as open_invoices_count' => fn ($q) => $q->whereIn('status', ['sent', 'overdue']),
        ]);

        $inviteUrl = $request->boolean('send_email', true)
            ? $this->sendInvite($client, $agency)
            : null;

        ProjectEvent::log($inviter->agency_id, null, 'client_invited', [
            'client_id'       => $client->id,
            'company_name'    => $client->company_name,
            'contact_user_id' => $client->contact_user_id,
            'invited_by'      => $inviter->id,
        ]);

        return $this->created(
            $this->clientPayload($client, $inviter, $inviteUrl),
            'Client invited successfully.',
        );
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['company_name'])) {
            $client->update(['company_name' => trim($validated['company_name'])]);
        }

        if (array_key_exists('tier', $validated)) {
            $client->update([
                'tier' => Client::normalizeTier($validated['tier']),
            ]);
        }

        $contact = $client->contactUser;
        if ($contact) {
            $contactUpdates = [];
            if (isset($validated['contact_name'])) {
                $contactUpdates['name'] = $validated['contact_name'];
            }
            if (isset($validated['contact_email'])) {
                $contactUpdates['email'] = $validated['contact_email'];
            }
            if (array_key_exists('job_title', $validated)) {
                $contactUpdates['job_title'] = $validated['job_title']
                    ? trim((string) $validated['job_title'])
                    : null;
            }
            if (array_key_exists('phone', $validated)) {
                $contactUpdates['phone'] = $validated['phone']
                    ? trim((string) $validated['phone'])
                    : null;
            }

            $meta = $contact->profile_meta ?? [];
            foreach (['first_name', 'last_name', 'address', 'phone_country'] as $metaKey) {
                if (array_key_exists($metaKey, $validated)) {
                    $value = $validated[$metaKey];
                    if ($value === null || $value === '') {
                        unset($meta[$metaKey]);
                    } else {
                        $meta[$metaKey] = is_string($value) ? trim($value) : $value;
                    }
                }
            }
            if (
                array_key_exists('first_name', $validated)
                || array_key_exists('last_name', $validated)
                || array_key_exists('address', $validated)
                || array_key_exists('phone_country', $validated)
            ) {
                $contactUpdates['profile_meta'] = $meta ?: null;
            }

            if ($contactUpdates) {
                $contact->update($contactUpdates);
            }
        }

        $client->refresh()->load([
            'agency:id,domain_slug',
            'contactUser:id,name,email,phone,job_title,avatar_path,profile_meta,updated_at',
            'contactUser.passwordSetupTokens',
        ]);
        $client->loadCount([
            'projects',
            'projects as active_projects_count' => fn ($q) => $q->where('status', '!=', 'completed'),
            'invoices as open_invoices_count' => fn ($q) => $q->whereIn('status', ['sent', 'overdue']),
        ]);

        ProjectEvent::log($request->user()->agency_id, null, 'client_updated', [
            'client_id'  => $client->id,
            'updated_by' => $request->user()->id,
        ]);

        return $this->success(
            $this->clientPayload($client, $request->user()),
            'Client updated successfully.',
        );
    }

    public function uploadAvatar(Request $request, Client $client): JsonResponse
    {
        $client->loadMissing('contactUser');
        $contact = $client->contactUser;

        if (! $contact) {
            return $this->notFound();
        }

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        if ($contact->avatar_path) {
            Storage::disk('public')->delete($contact->avatar_path);
        }

        $path = $request->file('avatar')->store(
            "agencies/{$request->user()->agency_id}/avatars",
            'public',
        );

        $contact->update(['avatar_path' => $path]);

        ProjectEvent::log($request->user()->agency_id, null, 'client_avatar_updated', [
            'client_id'  => $client->id,
            'user_id'    => $contact->id,
            'updated_by' => $request->user()->id,
        ]);

        $client->refresh()->load([
            'agency:id,domain_slug',
            'contactUser:id,name,email,phone,job_title,avatar_path,profile_meta,updated_at',
            'contactUser.passwordSetupTokens',
        ]);
        $client->loadCount([
            'projects',
            'projects as active_projects_count' => fn ($q) => $q->where('status', '!=', 'completed'),
            'invoices as open_invoices_count' => fn ($q) => $q->whereIn('status', ['sent', 'overdue']),
        ]);

        return $this->success(
            $this->clientPayload($client, $request->user()),
            'Profile photo updated.',
        );
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        if ($client->projects()->where('status', '!=', 'completed')->exists()) {
            return $this->error(
                'Cannot remove a client with active projects. Complete or reassign projects first.',
                [],
                422,
            );
        }

        $client->delete();

        ProjectEvent::log($request->user()->agency_id, null, 'client_removed', [
            'client_id'  => $client->id,
            'removed_by' => $request->user()->id,
        ]);

        return $this->success(message: 'Client removed.');
    }

    public function resendInvite(Request $request, Client $client): JsonResponse
    {
        $agency = Agency::findOrFail($request->user()->agency_id);
        $client->loadMissing('contactUser');

        if (!$client->contactUser) {
            return $this->notFound();
        }

        $inviteUrl = RateLimiter::attempt(
            'resend-client-invite:' . $client->id,
            1,
            fn () => $this->sendInvite($client, $agency),
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

    public function count(Request $request): JsonResponse
    {
        $count = Client::query()->visibleTo($request->user())->count();

        return $this->success(['count' => $count]);
    }

    private function sendInvite(Client $client, Agency $agency): string
    {
        $client->loadMissing('contactUser');
        $plain = $this->passwordSetupService->issueToken($client->contactUser);
        $inviteUrl = $this->buildInviteUrl($plain);

        $this->agencyMailer->send(
            $agency,
            new ClientInviteMail($client->contactUser, $client, $inviteUrl),
            $client->contactUser->email,
        );

        return $inviteUrl;
    }

    private function buildInviteUrl(string $plain): string
    {
        $base = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        return $base . '/set-password?token=' . $plain;
    }

    private function clientPayload(Client $client, User $viewer, ?string $inviteUrl = null): array
    {
        $canManage = $viewer->role === 'admin' || $viewer->hasPermission(Permissions::CLIENTS);
        $canInvite = $viewer->role === 'admin';
        $showContact = $viewer->role === 'admin' || $viewer->hasPermission(Permissions::CLIENT_CONTACT_DETAILS);

        $sentiment = $this->resolveSentimentSummary($client);
        $contact = $client->contactUser;
        $client->loadMissing('agency:id,domain_slug');

        $payload = [
            'id'                     => $client->id,
            'company_name'           => $client->company_name,
            'tier'                   => $client->tier,
            'contact_user_id'        => $client->contact_user_id,
            'domain_slug'            => $client->agency?->domain_slug,
            'avatar_url'             => $this->avatarUrl($contact?->avatar_path),
            'avatar_path'            => $contact?->avatar_path,
            'projects_count'         => (int) ($client->projects_count ?? 0),
            'active_projects_count'  => (int) ($client->active_projects_count ?? 0),
            'open_invoices_count'    => (int) ($client->open_invoices_count ?? 0),
            'sentiment_label'        => $sentiment['label'],
            'sentiment_trend'        => $sentiment['trend'],
            'at_risk'                => $sentiment['at_risk'],
            'invite_status'          => $this->inviteStatus($contact),
            'last_seen_at'           => $this->lastSeenAt($contact),
            'created_at'             => $client->created_at,
            'updated_at'             => $client->updated_at,
            'can_manage'             => $canManage,
            'can_invite'             => $canInvite,
            'can_chat'               => true,
        ];

        if ($showContact) {
            $meta = $contact?->profile_meta ?? [];
            $payload['contact_name'] = $contact?->name;
            $payload['contact_email'] = $contact?->email;
            $payload['job_title'] = $contact?->job_title;
            $payload['phone'] = $contact?->phone;
            $payload['address'] = $meta['address'] ?? null;
            $payload['first_name'] = $meta['first_name'] ?? null;
            $payload['last_name'] = $meta['last_name'] ?? null;
            $payload['phone_country'] = $meta['phone_country'] ?? null;
        }

        if ($inviteUrl !== null) {
            $payload['invite_url'] = $inviteUrl;
        }

        return $payload;
    }

    private function inviteStatus(?User $user): string
    {
        if (!$user) {
            return 'invite_not_sent';
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

    private function lastSeenAt(?User $user): ?string
    {
        if (!$user) {
            return null;
        }

        $lastActivity = DB::table('sessions')
            ->where('user_id', $user->id)
            ->max('last_activity');

        if (!$lastActivity) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $lastActivity)->toIso8601String();
    }

    private function resolveSentimentSummary(Client $client): array
    {
        $contactUserId = $client->contact_user_id;

        if (!$contactUserId) {
            return [
                'label'   => null,
                'trend'   => 'stable',
                'at_risk' => false,
            ];
        }

        $messages = Message::whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->where('sender_id', $contactUserId)
            ->whereNotNull('sentiment_score')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $latest = $messages->first();

        if (!$latest) {
            return [
                'label'   => null,
                'trend'   => 'stable',
                'at_risk' => false,
            ];
        }

        $scores = $messages->pluck('sentiment_score')->map(fn ($s) => (float) $s)->all();

        $atRisk = false;
        $projectIds = Project::where('client_id', $client->id)->pluck('id');
        foreach ($projectIds as $projectId) {
            if (SentimentService::isClientAtRisk($contactUserId, $projectId)) {
                $atRisk = true;
                break;
            }
        }

        return [
            'label'   => SentimentService::labelFromScore((float) $latest->sentiment_score),
            'trend'   => SentimentService::trendFromScores($scores),
            'at_risk' => $atRisk,
        ];
    }

    private function avatarUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return rtrim(config('app.url'), '/') . Storage::disk('public')->url($path);
    }
}
