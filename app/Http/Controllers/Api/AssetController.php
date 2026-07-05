<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ProjectEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    use ApiResponse;

    private const MAX_BYTES = 10 * 1024 * 1024; // 10MB

    // GET /api/v1/assets[?project_id=] — agency-wide, across all projects (Assets Library nav page)
    public function all(Request $request): JsonResponse
    {
        $query = Asset::with(['uploader:id,name', 'project:id,name,color'])
            ->orderByDesc('created_at');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $assets = $query->get()->map(fn (Asset $a) => [
            'id'             => $a->id,
            'original_name'  => $a->original_name,
            'file_path'      => $a->file_path,
            'version'        => $a->version,
            'is_deliverable' => $a->is_deliverable,
            'uploader_name'  => $a->uploader?->name,
            'project'        => $a->project ? ['id' => $a->project->id, 'name' => $a->project->name, 'color' => $a->project->color] : null,
            'created_at'     => $a->created_at,
        ]);

        return $this->success($assets);
    }

    // GET /api/v1/projects/{project}/assets
    public function index(Request $request, Project $project): JsonResponse
    {
        $assets = $project->assets()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Asset $a) => [
                'id'             => $a->id,
                'original_name'  => $a->original_name,
                'file_path'      => $a->file_path,
                'version'        => $a->version,
                'is_deliverable' => $a->is_deliverable,
                'uploader_name'  => $a->uploader?->name,
                'created_at'     => $a->created_at,
            ]);

        return $this->success($assets);
    }

    // POST /api/v1/projects/{project}/assets
    public function store(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,zip'],
        ]);

        $file = $request->file('file');
        $user = $request->user();

        $directory = "assets/{$project->agency_id}/{$project->id}";
        $path      = $file->store($directory, 'local');

        $version = $project->assets()->where('original_name', $file->getClientOriginalName())->count() + 1;

        $asset = Asset::create([
            'agency_id'     => $project->agency_id,
            'project_id'    => $project->id,
            'uploader_id'   => $user->id,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'version'       => $version,
            'is_deliverable'=> false,
        ]);

        ProjectEvent::log($user->agency_id, $project->id, 'asset_uploaded', [
            'asset_id'      => $asset->id,
            'original_name' => $asset->original_name,
            'version'       => $version,
            'uploader_id'   => $user->id,
        ]);

        return $this->created([
            'id'             => $asset->id,
            'original_name'  => $asset->original_name,
            'file_path'      => $asset->file_path,
            'version'        => $asset->version,
            'is_deliverable' => $asset->is_deliverable,
        ], 'Asset uploaded successfully.');
    }

    // PATCH /api/v1/projects/{project}/assets/{asset}/deliverable
    public function markDeliverable(Request $request, Project $project, Asset $asset): JsonResponse
    {
        if ($asset->project_id !== $project->id) {
            return $this->notFound('Asset not found on this project.');
        }

        $asset->update(['is_deliverable' => !$asset->is_deliverable]);

        return $this->success([
            'id'             => $asset->id,
            'is_deliverable' => $asset->is_deliverable,
        ]);
    }

    // DELETE /api/v1/projects/{project}/assets/{asset}
    public function destroy(Request $request, Project $project, Asset $asset): JsonResponse
    {
        if ($asset->project_id !== $project->id) {
            return $this->notFound('Asset not found on this project.');
        }

        if (Storage::disk('local')->exists($asset->file_path)) {
            Storage::disk('local')->delete($asset->file_path);
        }

        $asset->delete();

        return $this->success(null, 'Asset deleted.');
    }
}
