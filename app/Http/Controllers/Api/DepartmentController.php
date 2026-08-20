<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Department;
use App\Services\DepartmentBootstrapService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DepartmentBootstrapService $departmentBootstrap,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->departmentBootstrap->ensureForAgency($request->user()->agency_id);

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);

        return $this->success($departments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('departments', 'name')->where(
                    fn ($q) => $q->where('agency_id', $request->user()->agency_id),
                ),
            ],
        ]);

        $department = Department::create([
            'agency_id' => $request->user()->agency_id,
            'name'      => trim($validated['name']),
        ]);

        return $this->created($department, 'Department created.');
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('departments', 'name')
                    ->where(fn ($q) => $q->where('agency_id', $request->user()->agency_id))
                    ->ignore($department->id),
            ],
        ]);

        $newName = trim($validated['name']);
        $oldName = $department->name;

        $department->update(['name' => $newName]);

        if ($oldName !== $newName) {
            User::query()
                ->where('agency_id', $request->user()->agency_id)
                ->where('role', '!=', 'client')
                ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($oldName))])
                ->update(['department' => $newName]);
        }

        return $this->success($department, 'Department updated.');
    }

    public function destroy(Department $department): JsonResponse
    {
        if ($department->name === DepartmentBootstrapService::DEFAULT_NAME) {
            return $this->error('The default Management department cannot be removed.', [], 422);
        }

        $deptName = $department->name;

        User::query()
            ->where('agency_id', $department->agency_id)
            ->where('role', '!=', 'client')
            ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($deptName))])
            ->update(['department' => null]);

        $department->delete();

        return $this->success(message: 'Department removed.');
    }
}
