<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriveController extends Controller
{
    use ApiResponse;

    private const MAX_BYTES = 100 * 1024 * 1024; // 100MB
    private const RECYCLE_DAYS = 30;

    private const DEFAULT_FOLDERS = [
        ['name' => 'Discovery', 'icon' => 'chart', 'visibility' => 'agency', 'roles' => ['admin', 'pm', 'member', 'client'], 'allow_download' => true, 'allow_upload' => true],
        ['name' => 'Conceptualization', 'icon' => 'atom', 'visibility' => 'roles', 'roles' => ['admin', 'pm', 'member'], 'allow_download' => true, 'allow_upload' => true],
        ['name' => 'Refinement', 'icon' => 'moon', 'visibility' => 'roles', 'roles' => ['admin', 'pm', 'member'], 'allow_download' => false, 'allow_upload' => true],
        ['name' => 'Delivery', 'icon' => 'quantum', 'visibility' => 'agency', 'roles' => ['admin', 'pm', 'member', 'client'], 'allow_download' => true, 'allow_upload' => false],
    ];

    // GET /api/v1/drive
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureSeeded($user->agency_id, $user->id);
        $this->purgeExpiredFolders($user->agency_id);
        $this->purgeExpiredFiles($user->agency_id);

        $folders = DriveFolder::withTrashed()
            ->with(['files.uploader:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (DriveFolder $f) => $this->canViewFolder($f, $user, true))
            ->values()
            ->map(fn (DriveFolder $f) => $this->serializeFolder($f));

        $rootFiles = DriveFile::with('uploader:id,name')
            ->whereNull('folder_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DriveFile $f) => $this->serializeFile($f, 'root'));

        $trashedFiles = DriveFile::onlyTrashed()
            ->with('uploader:id,name')
            ->orderByDesc('deleted_at')
            ->get()
            ->filter(fn (DriveFile $f) => $this->canManageFile($f, $user) || $this->canAccessFile($f, $user))
            ->values()
            ->map(fn (DriveFile $f) => $this->serializeFile(
                $f,
                $f->folder_id ? null : 'root'
            ));

        return $this->success([
            'folders' => $folders,
            'root_files' => $rootFiles,
            'trashed_files' => $trashedFiles,
        ]);
    }

    // POST /api/v1/drive/folders
    public function storeFolder(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:32'],
            'visibility' => ['nullable', 'in:only_me,agency,roles'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'in:admin,pm,member,client'],
            'allow_download' => ['nullable', 'boolean'],
            'allow_upload' => ['nullable', 'boolean'],
        ]);

        $name = trim($data['name']);
        if ($this->nameTaken($user->agency_id, $name)) {
            return $this->error('A file or folder with this name already exists', [], 422);
        }

        $folder = DriveFolder::create([
            'agency_id' => $user->agency_id,
            'owner_id' => $user->id,
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
            'icon' => $data['icon'] ?? 'files',
            'visibility' => $data['visibility'] ?? 'only_me',
            'roles' => $data['roles'] ?? ['admin'],
            'allow_download' => array_key_exists('allow_download', $data) ? (bool) $data['allow_download'] : true,
            'allow_upload' => array_key_exists('allow_upload', $data) ? (bool) $data['allow_upload'] : true,
        ]);

        return $this->created($this->serializeFolder($folder->load('files')), 'Folder created.');
    }

    // PUT /api/v1/drive/folders/{folder}
    public function updateFolder(Request $request, DriveFolder $folder): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageFolder($folder, $user)) {
            return $this->forbidden('You cannot update this folder.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:32'],
            'visibility' => ['sometimes', 'in:only_me,agency,roles'],
            'roles' => ['sometimes', 'nullable', 'array'],
            'roles.*' => ['string', 'in:admin,pm,member,client'],
            'allow_download' => ['sometimes', 'boolean'],
            'allow_upload' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($this->nameTaken($user->agency_id, $name, excludeFolderId: $folder->id)) {
                return $this->error('A file or folder with this name already exists', [], 422);
            }
            $data['name'] = $name;
        }

        $folder->update($data);

        return $this->success($this->serializeFolder($folder->fresh()->load('files.uploader:id,name')), 'Folder updated.');
    }

    // POST /api/v1/drive/folders/{folder}/trash
    public function trashFolder(Request $request, DriveFolder $folder): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageFolder($folder, $user)) {
            return $this->forbidden('You cannot delete this folder.');
        }
        $folder->delete();
        return $this->success($this->serializeFolder($folder->fresh()), 'Folder moved to recycle bin.');
    }

    // POST /api/v1/drive/folders/{folder}/restore
    public function restoreFolder(Request $request, int $folder): JsonResponse
    {
        $user = $request->user();
        $model = DriveFolder::withTrashed()->find($folder);
        if (!$model) {
            return $this->notFound('Folder not found.');
        }
        if (!$this->canManageFolder($model, $user)) {
            return $this->forbidden('You cannot restore this folder.');
        }
        $model->restore();
        return $this->success($this->serializeFolder($model->fresh()->load('files.uploader:id,name')), 'Folder restored.');
    }

    // DELETE /api/v1/drive/folders/{folder}
    public function destroyFolder(Request $request, int $folder): JsonResponse
    {
        $user = $request->user();
        $model = DriveFolder::withTrashed()->find($folder);
        if (!$model) {
            return $this->notFound('Folder not found.');
        }
        if (!$this->canManageFolder($model, $user)) {
            return $this->forbidden('You cannot delete this folder.');
        }

        foreach ($model->files()->withTrashed()->get() as $file) {
            $this->deleteStoredFile($file);
            $file->forceDelete();
        }
        $model->forceDelete();

        return $this->success(null, 'Folder permanently deleted.');
    }

    // POST /api/v1/drive/files
    public function storeFile(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'folder_id' => ['nullable', 'integer'],
            'original_name' => ['nullable', 'string', 'max:255'],
        ]);

        $folderId = $request->filled('folder_id') ? (int) $request->folder_id : null;
        $folder = null;
        if ($folderId) {
            $folder = DriveFolder::find($folderId);
            if (!$folder) {
                return $this->notFound('Folder not found.');
            }
            if (!$this->canUploadToFolder($folder, $user)) {
                return $this->forbidden('You cannot upload to this folder.');
            }
        }

        $upload = $request->file('file');
        if ($upload->getSize() > self::MAX_BYTES) {
            return $this->error('File exceeds 100MB limit', [], 422);
        }

        $requestedName = trim((string) ($request->input('original_name') ?: $upload->getClientOriginalName()));
        $originalName = $this->uniqueFileName($user->agency_id, $folderId, $requestedName);

        $directory = $folderId
            ? "drive/{$user->agency_id}/folders/{$folderId}"
            : "drive/{$user->agency_id}/root";
        $path = $upload->store($directory, 'local');

        $file = DriveFile::create([
            'agency_id' => $user->agency_id,
            'folder_id' => $folderId,
            'uploader_id' => $user->id,
            'file_path' => $path,
            'original_name' => $originalName,
            'mime' => $upload->getClientMimeType() ?: 'application/octet-stream',
            'size' => $upload->getSize() ?: 0,
        ]);

        return $this->created(
            $this->serializeFile($file->load('uploader:id,name'), $folderId ? null : 'root'),
            'File uploaded.'
        );
    }

    // GET /api/v1/drive/files/{file}/download
    public function download(Request $request, DriveFile $file): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        if (!$this->canAccessFile($file, $user)) {
            return $this->forbidden('You cannot download this file.');
        }
        if ($file->folder_id) {
            $folder = $file->folder;
            if ($folder && !$this->canDownloadFolder($folder, $user)) {
                return $this->forbidden('Downloads are restricted for this folder.');
            }
        }
        if (!Storage::disk('local')->exists($file->file_path)) {
            return $this->notFound('File missing on disk.');
        }

        return Storage::disk('local')->download($file->file_path, $file->original_name, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
        ]);
    }

    // PUT /api/v1/drive/files/{file}
    public function updateFile(Request $request, DriveFile $file): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageFile($file, $user)) {
            return $this->forbidden('You cannot rename this file.');
        }

        $data = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($data['original_name']);
        $name = $this->preserveExtension($file->original_name, $name);
        if ($this->nameTaken($user->agency_id, $name, excludeFileId: $file->id, folderId: $file->folder_id)) {
            return $this->error(
                $file->folder_id
                    ? 'A file with this name already exists in this folder'
                    : 'A file or folder with this name already exists',
                [],
                422
            );
        }

        $file->update(['original_name' => $name]);

        return $this->success(
            $this->serializeFile($file->fresh()->load('uploader:id,name'), $file->folder_id ? null : 'root'),
            'File renamed.'
        );
    }

    // POST /api/v1/drive/files/{file}/copy
    public function copyFile(Request $request, DriveFile $file): JsonResponse
    {
        $user = $request->user();
        if (!$this->canAccessFile($file, $user)) {
            return $this->forbidden('You cannot copy this file.');
        }
        if ($file->folder_id) {
            $folder = $file->folder;
            if ($folder && !$this->canUploadToFolder($folder, $user)) {
                return $this->forbidden('You cannot upload to this folder.');
            }
        }
        if (!Storage::disk('local')->exists($file->file_path)) {
            return $this->notFound('File missing on disk.');
        }

        $copyName = $this->copyFileName(
            $file->original_name,
            $this->takenNames($user->agency_id, $file->folder_id)
        );

        $directory = $file->folder_id
            ? "drive/{$user->agency_id}/folders/{$file->folder_id}"
            : "drive/{$user->agency_id}/root";
        $newPath = $directory.'/'.uniqid('copy_', true).'_'.basename($file->file_path);
        Storage::disk('local')->copy($file->file_path, $newPath);

        $copy = DriveFile::create([
            'agency_id' => $user->agency_id,
            'folder_id' => $file->folder_id,
            'uploader_id' => $user->id,
            'file_path' => $newPath,
            'original_name' => $copyName,
            'mime' => $file->mime,
            'size' => $file->size,
        ]);

        return $this->created(
            $this->serializeFile($copy->load('uploader:id,name'), $file->folder_id ? null : 'root'),
            'File copied.'
        );
    }

    // PUT /api/v1/drive/files/{file}/content
    public function replaceContent(Request $request, DriveFile $file): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageFile($file, $user)) {
            return $this->forbidden('You cannot edit this file.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $upload = $request->file('file');
        Storage::disk('local')->put($file->file_path, file_get_contents($upload->getRealPath()));

        $file->update([
            'mime' => $upload->getClientMimeType() ?: $file->mime,
            'size' => $upload->getSize() ?: 0,
        ]);

        return $this->success(
            $this->serializeFile($file->fresh()->load('uploader:id,name'), $file->folder_id ? null : 'root'),
            'File saved.'
        );
    }

    // DELETE /api/v1/drive/files/{file} — soft delete (recycle bin)
    public function destroyFile(Request $request, DriveFile $file): JsonResponse
    {
        $user = $request->user();
        if (!$this->canManageFile($file, $user)) {
            return $this->forbidden('You cannot delete this file.');
        }
        $file->delete();
        return $this->success(null, 'File moved to recycle bin.');
    }

    // POST /api/v1/drive/files/{file}/restore
    public function restoreFile(Request $request, int $file): JsonResponse
    {
        $user = $request->user();
        $model = DriveFile::onlyTrashed()->find($file);
        if (!$model) {
            return $this->notFound('File not found.');
        }
        if (!$this->canManageFile($model, $user)) {
            return $this->forbidden('You cannot restore this file.');
        }
        $model->restore();
        return $this->success(
            $this->serializeFile($model->fresh()->load('uploader:id,name'), $model->folder_id ? null : 'root'),
            'File restored.'
        );
    }

    // DELETE /api/v1/drive/files/{file}/force — permanent delete
    public function forceDestroyFile(Request $request, int $file): JsonResponse
    {
        $user = $request->user();
        $model = DriveFile::onlyTrashed()->find($file);
        if (!$model) {
            return $this->notFound('File not found.');
        }
        if (!$this->canManageFile($model, $user)) {
            return $this->forbidden('You cannot delete this file.');
        }
        $this->deleteStoredFile($model);
        $model->forceDelete();
        return $this->success(null, 'File permanently deleted.');
    }

    private function ensureSeeded(int $agencyId, int $ownerId): void
    {
        if (DriveFolder::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_FOLDERS as $row) {
            DriveFolder::create([
                'agency_id' => $agencyId,
                'owner_id' => $ownerId,
                'name' => $row['name'],
                'description' => '',
                'icon' => $row['icon'],
                'visibility' => $row['visibility'],
                'roles' => $row['roles'],
                'allow_download' => $row['allow_download'],
                'allow_upload' => $row['allow_upload'],
            ]);
        }
    }

    private function purgeExpiredFolders(int $agencyId): void
    {
        $cutoff = now()->subDays(self::RECYCLE_DAYS);
        $expired = DriveFolder::onlyTrashed()
            ->where('agency_id', $agencyId)
            ->where('deleted_at', '<', $cutoff)
            ->get();

        foreach ($expired as $folder) {
            foreach ($folder->files()->withTrashed()->get() as $file) {
                $this->deleteStoredFile($file);
                $file->forceDelete();
            }
            $folder->forceDelete();
        }
    }

    private function purgeExpiredFiles(int $agencyId): void
    {
        $cutoff = now()->subDays(self::RECYCLE_DAYS);
        $expired = DriveFile::onlyTrashed()
            ->where('agency_id', $agencyId)
            ->where('deleted_at', '<', $cutoff)
            ->get();

        foreach ($expired as $file) {
            $this->deleteStoredFile($file);
            $file->forceDelete();
        }
    }

    private function serializeFolder(DriveFolder $folder): array
    {
        $files = ($folder->relationLoaded('files') ? $folder->files : collect())
            ->map(fn (DriveFile $f) => $this->serializeFile($f))
            ->values()
            ->all();

        return [
            'id' => $folder->id,
            'agencyId' => $folder->agency_id,
            'name' => $folder->name,
            'description' => $folder->description ?? '',
            'icon' => $folder->icon,
            'ownerId' => $folder->owner_id,
            'visibility' => $folder->visibility,
            'roles' => $folder->roles ?? [],
            'allowDownload' => (bool) $folder->allow_download,
            'allowUpload' => (bool) $folder->allow_upload,
            'assetIds' => [],
            'localFiles' => $files,
            'createdAt' => optional($folder->created_at)?->toISOString(),
            'deletedAt' => optional($folder->deleted_at)?->toISOString(),
        ];
    }

    private function serializeFile(DriveFile $file, ?string $location = null): array
    {
        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'size' => (int) $file->size,
            'mime' => $file->mime,
            'created_at' => optional($file->created_at)?->toISOString(),
            'deletedAt' => optional($file->deleted_at)?->toISOString(),
            'uploader_name' => $file->uploader?->name,
            'source' => 'drive',
            'location' => $location,
            'folder_id' => $file->folder_id,
            'version' => 1,
            'is_deliverable' => false,
            'project' => null,
        ];
    }

    private function canViewFolder(DriveFolder $folder, $user, bool $includeTrashed = false): bool
    {
        if ($folder->trashed() && !$includeTrashed) {
            return false;
        }
        if ($folder->trashed()) {
            return $this->canManageFolder($folder, $user);
        }
        if ($folder->owner_id && (int) $folder->owner_id === (int) $user->id) {
            return true;
        }
        if ($user->role === 'admin') {
            return true;
        }
        if ($folder->visibility === 'only_me') {
            return false;
        }
        if ($folder->visibility === 'agency') {
            return true;
        }
        if ($folder->visibility === 'roles') {
            return in_array($user->role, $folder->roles ?? [], true);
        }
        return false;
    }

    private function canManageFolder(DriveFolder $folder, $user): bool
    {
        return $user->role === 'admin'
            || $user->role === 'pm'
            || ((int) $folder->owner_id === (int) $user->id);
    }

    private function canDownloadFolder(DriveFolder $folder, $user): bool
    {
        return $this->canViewFolder($folder, $user) && $folder->allow_download !== false;
    }

    private function canUploadToFolder(DriveFolder $folder, $user): bool
    {
        if (!$this->canViewFolder($folder, $user)) {
            return false;
        }
        if ((int) $folder->owner_id === (int) $user->id || $user->role === 'admin' || $user->role === 'pm') {
            return true;
        }
        return $folder->allow_upload !== false;
    }

    private function canAccessFile(DriveFile $file, $user): bool
    {
        if (!$file->folder_id) {
            return in_array($user->role, ['admin', 'pm', 'member'], true);
        }
        $folder = $file->folder;
        return $folder && $this->canViewFolder($folder, $user);
    }

    private function canManageFile(DriveFile $file, $user): bool
    {
        if ($user->role === 'admin' || $user->role === 'pm') {
            return true;
        }
        return (int) $file->uploader_id === (int) $user->id;
    }

    private function deleteStoredFile(DriveFile $file): void
    {
        if ($file->file_path && Storage::disk('local')->exists($file->file_path)) {
            Storage::disk('local')->delete($file->file_path);
        }
    }

    private function takenNames(int $agencyId, ?int $folderId, ?int $excludeFileId = null, ?int $excludeFolderId = null): array
    {
        if ($folderId) {
            return DriveFile::where('folder_id', $folderId)
                ->when($excludeFileId, fn ($q) => $q->where('id', '!=', $excludeFileId))
                ->pluck('original_name')
                ->all();
        }

        $folderNames = DriveFolder::where('agency_id', $agencyId)
            ->when($excludeFolderId, fn ($q) => $q->where('id', '!=', $excludeFolderId))
            ->pluck('name')
            ->all();
        $fileNames = DriveFile::whereNull('folder_id')
            ->when($excludeFileId, fn ($q) => $q->where('id', '!=', $excludeFileId))
            ->pluck('original_name')
            ->all();

        return array_merge($folderNames, $fileNames);
    }

    private function nameTaken(
        int $agencyId,
        string $name,
        ?int $excludeFileId = null,
        ?int $excludeFolderId = null,
        ?int $folderId = null
    ): bool {
        $key = mb_strtolower(trim($name));
        foreach ($this->takenNames($agencyId, $folderId, $excludeFileId, $excludeFolderId) as $existing) {
            if (mb_strtolower(trim((string) $existing)) === $key) {
                return true;
            }
        }
        return false;
    }

    private function uniqueFileName(int $agencyId, ?int $folderId, string $originalName): string
    {
        $name = trim($originalName) ?: 'file';
        $taken = $this->takenNames($agencyId, $folderId);
        if (!$this->nameInList($name, $taken)) {
            return $name;
        }
        [$base, $ext] = $this->splitBaseExt($name);
        $n = 1;
        while ($this->nameInList("{$base} ({$n}){$ext}", $taken)) {
            $n++;
        }
        return "{$base} ({$n}){$ext}";
    }

    private function copyFileName(string $originalName, array $taken): string
    {
        [$base, $ext] = $this->splitBaseExt($originalName);
        $candidate = "{$base} (copy){$ext}";
        if (!$this->nameInList($candidate, $taken)) {
            return $candidate;
        }
        $n = 2;
        while ($this->nameInList("{$base} (copy {$n}){$ext}", $taken)) {
            $n++;
        }
        return "{$base} (copy {$n}){$ext}";
    }

    private function nameInList(string $candidate, array $taken): bool
    {
        $key = mb_strtolower(trim($candidate));
        foreach ($taken as $existing) {
            if (mb_strtolower(trim((string) $existing)) === $key) {
                return true;
            }
        }
        return false;
    }

    private function splitBaseExt(string $name): array
    {
        $s = trim($name) ?: 'file';
        $i = strrpos($s, '.');
        if ($i !== false && $i > 0 && $i < strlen($s) - 1) {
            return [substr($s, 0, $i), substr($s, $i)];
        }
        return [$s, ''];
    }

    private function preserveExtension(string $oldName, string $nextName): string
    {
        $trimmed = trim($nextName);
        if ($trimmed === '') {
            return $oldName;
        }
        $oldExt = str_contains($oldName, '.') ? substr($oldName, strrpos($oldName, '.')) : '';
        if ($oldExt !== '' && !str_contains($trimmed, '.')) {
            return $trimmed.$oldExt;
        }
        return $trimmed;
    }
}
