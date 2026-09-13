<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ProjectResource;
use App\Http\Traits\ApiResponse;
use App\Helpers\ProjectProgress;
use App\Models\Agency;
use App\Models\Client;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalController extends Controller
{
    use ApiResponse;

    // GET /api/v1/portal/{slug}/branding — public, no auth required
    public function publicBranding(string $slug): JsonResponse
    {
        $agency = Agency::where('domain_slug', $slug)->first();

        if (!$agency) {
            return $this->notFound('Portal not found.');
        }

        return $this->success($this->formatBranding($agency));
    }

    // GET /api/v1/portal/{slug}/branding — authenticated client (legacy)
    public function branding(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found for this agency.');
        }

        return $this->success($this->formatBranding($user->agency));
    }

    private function formatBranding(Agency $agency): array
    {
        $colors = $agency->brand_colors ?? [];

        $logoUrl = null;
        if ($agency->logo_path) {
            $logoUrl = str_starts_with($agency->logo_path, 'http')
                ? $agency->logo_path
                : Storage::disk('public')->url($agency->logo_path);
        }

        $logoDarkUrl = null;
        if ($agency->logo_dark_path ?? null) {
            $logoDarkUrl = str_starts_with($agency->logo_dark_path, 'http')
                ? $agency->logo_dark_path
                : Storage::disk('public')->url($agency->logo_dark_path);
        }

        return [
            'agency_name'    => $agency->name,
            'logo_url'       => $logoUrl,
            'logo_dark_url'  => $logoDarkUrl,
            'primary_color'  => $colors['primary'] ?? '#802AEE',
            'primary_light'  => $colors['light'] ?? '#f3e8ff',
            'currency'       => strtoupper($agency->currency ?? 'USD'),
            'email'          => $agency->email,
            'phone'          => $agency->phone,
            'website'        => $agency->website,
            'address'        => $agency->address,
            'social_links'   => $agency->social_links ?? new \stdClass(),
        ];
    }

    // GET /api/v1/portal/{slug}/projects — the authenticated client's own linked project(s)
    public function projects(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found for this agency.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();

        if (!$client) {
            return $this->notFound('No client profile found for your user account.');
        }

        $projects = $client->projects()
            ->with(['client:id,company_name', 'latestHealthScore'])
            ->orderByDesc('created_at')
            ->get();

        return $this->success(ProjectResource::collection($projects));
    }

    // GET /api/v1/portal/{slug}/projects/{project}/progress — milestone progress for ProgressView
    public function progress(Request $request, string $slug, Project $project): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found for this agency.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();

        if (!$client || $project->client_id !== $client->id) {
            return $this->forbidden('You do not have access to this project.');
        }

        $project->load('tasks');
        $milestones = $project->milestones()->with('tasks')->orderBy('due_date')->get();

        $tasks = $project->tasks;
        $taskTotal = $tasks->count();
        $taskDone = $tasks->where('status', 'done')->count();
        $taskInProgress = $tasks->whereIn('status', ['in_progress', 'in_review'])->count();
        $taskTodo = $tasks->where('status', 'todo')->count();
        $taskBlocked = $tasks->where('status', 'blocked')->count();
        $taskOther = max(0, $taskTotal - $taskDone - $taskInProgress - $taskTodo - $taskBlocked);

        $milestonePayload = $milestones->map(function ($m) {
            $total = $m->tasks->count();
            $done  = $m->tasks->where('status', 'done')->count();

            return [
                'id'         => $m->id,
                'title'      => $m->title,
                'status'     => $m->status === 'completed' ? 'completed' : ($total > 0 && $done > 0 ? 'active' : 'pending'),
                'progress'   => $total > 0 ? (int) round(($done / $total) * 100) : ($m->status === 'completed' ? 100 : 0),
                'due_date'   => $m->due_date?->toDateString(),
                'task_total' => $total,
                'task_done'  => $done,
            ];
        })->values();

        $completedMs = $milestonePayload->where('status', 'completed')->count();
        $activeMs = $milestonePayload->where('status', 'active')->count();
        $pendingMs = $milestonePayload->where('status', 'pending')->count();

        return $this->success([
            'project' => [
                'name'             => $project->name,
                'progress_percent' => ProjectProgress::calculate($project),
                'status'           => $project->status,
                'start_date'       => $project->start_date?->toDateString(),
                'end_date'         => $project->end_date?->toDateString(),
            ],
            'milestones' => $milestonePayload,
            'stats' => [
                'milestones' => [
                    'total'     => $milestonePayload->count(),
                    'completed' => $completedMs,
                    'active'    => $activeMs,
                    'pending'   => $pendingMs,
                ],
                'tasks' => [
                    'total'       => $taskTotal,
                    'done'        => $taskDone,
                    'in_progress' => $taskInProgress,
                    'todo'        => $taskTodo,
                    'blocked'     => $taskBlocked,
                    'other'       => $taskOther,
                ],
            ],
        ]);
    }

    // GET /api/v1/portal/{slug}/invoices
    public function invoices(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();
        if (!$client) {
            return $this->success([]);
        }

        $invoices = Invoice::with(['project:id,name'])
            ->where('client_id', $client->id)
            ->whereIn('status', ['sent', 'paid'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($inv) => $inv->displayStatus() !== 'draft');

        return $this->success(InvoiceResource::collection($invoices->values()));
    }

    // GET /api/v1/portal/{slug}/projects/{project}/team
    public function team(Request $request, string $slug, Project $project): JsonResponse
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return $this->notFound('Portal not found.');
        }

        $client = Client::where('contact_user_id', $user->id)->first();

        if (!$client || $project->client_id !== $client->id) {
            return $this->forbidden('You do not have access to this project.');
        }

        $project->load([
            'teamMembers:id',
            'tasks:id,project_id,assignee_id',
        ]);

        $onProjectIds = $project->teamMembers
            ->pluck('id')
            ->concat($project->tasks->pluck('assignee_id'))
            ->filter()
            ->unique()
            ->all();

        // Clients can message any active agency staff — not only explicitly assigned members.
        $members = User::query()
            ->where('agency_id', $project->agency_id)
            ->whereIn('role', ['admin', 'pm', 'member'])
            ->whereNull('access_revoked_at')
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'job_title', 'avatar_path', 'department'])
            ->map(fn ($u) => [
                'id'          => $u->id,
                'name'        => $u->name,
                'role'        => $u->role,
                'job_title'   => $u->job_title,
                'department'  => $u->department,
                'avatar_path' => $u->avatar_path,
                'on_project'  => in_array($u->id, $onProjectIds, true),
            ])
            ->sortByDesc(fn ($m) => $m['on_project'] ? 1 : 0)
            ->values();

        return $this->success($members);
    }

    // GET /api/v1/portal/{slug}/assets — client folder (named after company) + files
    public function assets(Request $request, string $slug): JsonResponse
    {
        [$client, $error] = $this->resolvePortalClient($request, $slug);
        if ($error) {
            return $error;
        }

        $folder = $this->ensureClientFolder($client, $request->user());
        $folder->load(['files.uploader:id,name']);

        return $this->success([
            'folder' => [
                'id'          => $folder->id,
                'name'        => $folder->name,
                'description' => $folder->description,
                'allow_upload'=> (bool) $folder->allow_upload,
            ],
            'files' => $folder->files
                ->sortByDesc('created_at')
                ->values()
                ->map(fn (DriveFile $f) => $this->serializePortalFile($f)),
        ]);
    }

    // POST /api/v1/portal/{slug}/assets — upload into the client folder
    public function uploadAsset(Request $request, string $slug): JsonResponse
    {
        [$client, $error] = $this->resolvePortalClient($request, $slug);
        if ($error) {
            return $error;
        }

        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $folder = $this->ensureClientFolder($client, $request->user());
        $upload = $request->file('file');
        if ($upload->getSize() > 100 * 1024 * 1024) {
            return $this->error('File exceeds 100MB limit', [], 422);
        }

        $originalName = $this->uniquePortalFileName(
            $folder->id,
            $upload->getClientOriginalName()
        );

        $directory = "drive/{$client->agency_id}/folders/{$folder->id}";
        $path = $upload->store($directory, 'local');

        $file = DriveFile::create([
            'agency_id'     => $client->agency_id,
            'folder_id'     => $folder->id,
            'uploader_id'   => $request->user()->id,
            'file_path'     => $path,
            'original_name' => $originalName,
            'mime'          => $upload->getClientMimeType() ?: 'application/octet-stream',
            'size'          => $upload->getSize() ?: 0,
        ]);

        return $this->created(
            $this->serializePortalFile($file->load('uploader:id,name')),
            'File uploaded.'
        );
    }

    // GET /api/v1/portal/{slug}/assets/files/{file}/download
    public function downloadAsset(Request $request, string $slug, int $file): StreamedResponse|JsonResponse
    {
        [$client, $error] = $this->resolvePortalClient($request, $slug);
        if ($error) {
            return $error;
        }

        $folder = $this->ensureClientFolder($client, $request->user());
        $model = DriveFile::where('id', $file)
            ->where('folder_id', $folder->id)
            ->first();

        if (!$model) {
            return $this->notFound('File not found.');
        }
        if (!Storage::disk('local')->exists($model->file_path)) {
            return $this->notFound('File missing on disk.');
        }

        return Storage::disk('local')->download($model->file_path, $model->original_name, [
            'Content-Type' => $model->mime ?: 'application/octet-stream',
        ]);
    }

    // DELETE /api/v1/portal/{slug}/assets/files/{file}
    public function destroyAsset(Request $request, string $slug, int $file): JsonResponse
    {
        [$client, $error] = $this->resolvePortalClient($request, $slug);
        if ($error) {
            return $error;
        }

        $folder = $this->ensureClientFolder($client, $request->user());
        $model = DriveFile::where('id', $file)
            ->where('folder_id', $folder->id)
            ->first();

        if (!$model) {
            return $this->notFound('File not found.');
        }

        if ((int) $model->uploader_id !== (int) $request->user()->id) {
            return $this->forbidden('You can only remove files you uploaded.');
        }

        $model->delete();

        return $this->success(null, 'File removed.');
    }

    /**
     * @return array{0:?Client,1:?JsonResponse}
     */
    private function resolvePortalClient(Request $request, string $slug): array
    {
        $user = $request->user();

        if ($user->agency->domain_slug !== $slug) {
            return [null, $this->notFound('Portal not found.')];
        }

        $client = Client::where('contact_user_id', $user->id)->first();
        if (!$client) {
            return [null, $this->notFound('No client profile found for your user account.')];
        }

        return [$client, null];
    }

    private function ensureClientFolder(Client $client, User $user): DriveFolder
    {
        $baseName = trim((string) $client->company_name) ?: 'Client uploads';

        $folder = DriveFolder::query()
            ->where('agency_id', $client->agency_id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($baseName)])
            ->first();

        if (!$folder) {
            $folder = DriveFolder::create([
                'agency_id'      => $client->agency_id,
                'owner_id'       => $user->id,
                'name'           => $baseName,
                'description'    => 'Files shared by '.$baseName,
                'icon'           => 'files',
                'visibility'     => 'roles',
                'roles'          => ['admin', 'pm', 'member', 'client'],
                'allow_download' => true,
                'allow_upload'   => true,
            ]);
        } else {
            $roles = collect($folder->roles ?? [])
                ->merge(['admin', 'pm', 'member', 'client'])
                ->unique()
                ->values()
                ->all();
            $folder->update([
                'roles'          => $roles,
                'visibility'     => 'roles',
                'allow_upload'   => true,
                'allow_download' => true,
            ]);
        }

        return $folder->fresh();
    }

    private function serializePortalFile(DriveFile $file): array
    {
        return [
            'id'            => $file->id,
            'original_name' => $file->original_name,
            'size'          => (int) $file->size,
            'mime'          => $file->mime,
            'created_at'    => optional($file->created_at)?->toISOString(),
            'uploader_name' => $file->uploader?->name,
            'uploader_id'   => $file->uploader_id,
        ];
    }

    private function uniquePortalFileName(int $folderId, string $requested): string
    {
        $name = trim($requested) ?: 'upload.bin';
        $taken = DriveFile::where('folder_id', $folderId)->pluck('original_name')->all();
        if (!in_array($name, $taken, true)) {
            return $name;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME) ?: 'file';
        $i = 2;
        do {
            $candidate = $ext ? "{$base} ({$i}).{$ext}" : "{$base} ({$i})";
            $i++;
        } while (in_array($candidate, $taken, true));

        return $candidate;
    }
}
