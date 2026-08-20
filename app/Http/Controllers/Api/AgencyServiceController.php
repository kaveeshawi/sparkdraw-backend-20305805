<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AgencyService;
use App\Models\Project;
use App\Services\ServiceCatalogBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AgencyServiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ServiceCatalogBootstrapService $serviceCatalogBootstrap,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $agencyId = $request->user()->agency_id;
        $this->serviceCatalogBootstrap->ensureForAgency($agencyId);

        $services = AgencyService::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'created_at']);

        return $this->success($services);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('agency_services', 'name')->where(
                    fn ($q) => $q->where('agency_id', $request->user()->agency_id),
                ),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $service = AgencyService::create([
            'agency_id'   => $request->user()->agency_id,
            'name'        => trim($validated['name']),
            'description' => isset($validated['description']) ? trim($validated['description']) : null,
        ]);

        return $this->created($service, 'Service created.');
    }

    public function update(Request $request, AgencyService $agencyService): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('agency_services', 'name')
                    ->where(fn ($q) => $q->where('agency_id', $request->user()->agency_id))
                    ->ignore($agencyService->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $newName = trim($validated['name']);
        $oldName = $agencyService->name;

        $agencyService->update([
            'name'        => $newName,
            'description' => array_key_exists('description', $validated)
                ? (is_string($validated['description']) ? trim($validated['description']) : null)
                : $agencyService->description,
        ]);

        if ($oldName !== $newName) {
            Project::query()
                ->where('agency_id', $request->user()->agency_id)
                ->whereRaw('LOWER(TRIM(type)) = ?', [mb_strtolower(trim($oldName))])
                ->update(['type' => $newName]);
        }

        return $this->success($agencyService, 'Service updated.');
    }

    public function destroy(Request $request, AgencyService $agencyService): JsonResponse
    {
        $inUse = Project::query()
            ->where('agency_id', $agencyService->agency_id)
            ->whereRaw('LOWER(TRIM(type)) = ?', [mb_strtolower(trim($agencyService->name))])
            ->exists();

        if ($inUse) {
            return $this->error(
                'This service is linked to one or more projects. Rename or reassign those projects before removing it.',
                [],
                422,
            );
        }

        $agencyService->delete();

        return $this->success(message: 'Service removed.');
    }
}
