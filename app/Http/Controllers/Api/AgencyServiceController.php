<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\AgencyService;
use App\Models\AgencyServicePackage;
use App\Models\Project;
use App\Services\ServiceCatalogBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            ->with(['packages' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'created_at']);

        return $this->success($services);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateServicePayload($request);

        $service = DB::transaction(function () use ($request, $validated) {
            $service = AgencyService::create([
                'agency_id'   => $request->user()->agency_id,
                'name'        => trim($validated['name']),
                'description' => isset($validated['description'])
                    ? (is_string($validated['description']) ? trim($validated['description']) : null)
                    : null,
            ]);

            $this->syncPackages(
                $service,
                $request->user()->agency_id,
                $validated['packages'] ?? [],
            );

            return $service->load(['packages' => fn ($q) => $q->orderBy('name')]);
        });

        return $this->created($service, 'Service created.');
    }

    public function update(Request $request, AgencyService $agencyService): JsonResponse
    {
        $validated = $this->validateServicePayload($request, $agencyService->id);

        $service = DB::transaction(function () use ($request, $agencyService, $validated) {
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

            if (array_key_exists('packages', $validated)) {
                $this->syncPackages(
                    $agencyService,
                    $request->user()->agency_id,
                    $validated['packages'] ?? [],
                );
            }

            return $agencyService->fresh()->load(['packages' => fn ($q) => $q->orderBy('name')]);
        });

        return $this->success($service, 'Service updated.');
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

    /**
     * @return array<string, mixed>
     */
    private function validateServicePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('agency_services', 'name')
                    ->where(fn ($q) => $q->where('agency_id', $request->user()->agency_id))
                    ->ignore($ignoreId),
            ],
            'description'               => ['sometimes', 'nullable', 'string', 'max:2000'],
            'packages'                  => ['sometimes', 'nullable', 'array'],
            'packages.*.id'             => ['sometimes', 'nullable', 'integer'],
            'packages.*.name'           => ['required_with:packages', 'string', 'max:100'],
            'packages.*.includes'       => ['sometimes', 'nullable', 'string', 'max:4000'],
            'packages.*.price'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'packages.*.duration_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'packages.*.suggested_roles'   => ['sometimes', 'nullable', 'array'],
            'packages.*.suggested_roles.*' => ['string', 'max:100'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    private function syncPackages(AgencyService $service, int $agencyId, array $packages): void
    {
        $normalized = [];
        $seenNames = [];

        foreach ($packages as $index => $pkg) {
            $name = trim((string) ($pkg['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    "packages.{$index}.name" => ['Package name is required.'],
                ]);
            }

            $key = mb_strtolower($name);
            if (isset($seenNames[$key])) {
                throw ValidationException::withMessages([
                    "packages.{$index}.name" => ['Duplicate package name under this service.'],
                ]);
            }
            $seenNames[$key] = true;

            $roles = isset($pkg['suggested_roles']) && is_array($pkg['suggested_roles'])
                ? array_values(array_unique(array_filter(array_map(
                    fn ($r) => is_string($r) ? trim($r) : '',
                    $pkg['suggested_roles'],
                ))))
                : null;

            $normalized[] = [
                'id'              => isset($pkg['id']) ? (int) $pkg['id'] : null,
                'name'            => $name,
                'includes'        => isset($pkg['includes']) && is_string($pkg['includes'])
                    ? trim($pkg['includes'])
                    : null,
                'price'           => array_key_exists('price', $pkg) && $pkg['price'] !== null && $pkg['price'] !== ''
                    ? (float) $pkg['price']
                    : null,
                'duration_hours'  => array_key_exists('duration_hours', $pkg) && $pkg['duration_hours'] !== null && $pkg['duration_hours'] !== ''
                    ? (int) $pkg['duration_hours']
                    : null,
                'suggested_roles' => $roles,
            ];
        }

        $keepIds = [];

        foreach ($normalized as $pkg) {
            if ($pkg['id']) {
                $existing = AgencyServicePackage::query()
                    ->where('agency_service_id', $service->id)
                    ->where('id', $pkg['id'])
                    ->first();

                if (! $existing) {
                    throw ValidationException::withMessages([
                        'packages' => ['One or more packages do not belong to this service.'],
                    ]);
                }

                $existing->update([
                    'name'            => $pkg['name'],
                    'includes'        => $pkg['includes'],
                    'price'           => $pkg['price'],
                    'duration_hours'  => $pkg['duration_hours'],
                    'suggested_roles' => $pkg['suggested_roles'],
                ]);
                $keepIds[] = $existing->id;
                continue;
            }

            $created = AgencyServicePackage::create([
                'agency_id'         => $agencyId,
                'agency_service_id' => $service->id,
                'name'              => $pkg['name'],
                'includes'          => $pkg['includes'],
                'price'             => $pkg['price'],
                'duration_hours'    => $pkg['duration_hours'],
                'suggested_roles'   => $pkg['suggested_roles'],
            ]);
            $keepIds[] = $created->id;
        }

        AgencyServicePackage::query()
            ->where('agency_service_id', $service->id)
            ->when(count($keepIds) > 0, fn ($q) => $q->whereNotIn('id', $keepIds))
            ->when(count($keepIds) === 0, fn ($q) => $q)
            ->delete();
    }
}
