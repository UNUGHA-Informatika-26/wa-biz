<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListDepartmentsRequest;
use App\Http\Requests\Api\StoreDepartmentRequest;
use App\Http\Requests\Api\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends Controller
{
    public function index(ListDepartmentsRequest $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $validated = $request->validated();

        $query = Department::query()
            ->where('organization_id', $request->user()->organization_id);

        if (isset($validated['active']) && $validated['active'] !== null) {
            $query->where('active', filter_var($validated['active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (filled($validated['search'] ?? null)) {
            $query->where('name', 'ILIKE', '%'.$validated['search'].'%');
        }

        $departments = $query
            ->orderByDesc('active')
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $departments->getCollection()
                ->map(fn (Department $department): array => $this->departmentData($department))
                ->values(),
            'meta' => [
                'current_page' => $departments->currentPage(),
                'last_page' => $departments->lastPage(),
                'per_page' => $departments->perPage(),
                'total' => $departments->total(),
            ],
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        try {
            $department = $request->user()
                ->organization()
                ->firstOrFail()
                ->departments()
                ->create($this->withoutNullActive($request->validated()));
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->duplicateNameResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $this->departmentData($department),
        ], 201);
    }

    public function show(Request $request, int $department): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $department = $this->findForOrganization($request, $department);

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->departmentData($department),
                'active_cs_members_count' => $department->csMembers()->where('active', true)->count(),
                'conversation_count' => $department->conversations()->count(),
            ],
        ]);
    }

    public function update(UpdateDepartmentRequest $request, int $department): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $department = $this->findForOrganization($request, $department);

        try {
            $department->update($this->withoutNullActive($request->validated()));
            $department->refresh();
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->duplicateNameResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $this->departmentData($department),
        ]);
    }

    public function destroy(Request $request, int $department): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        $deleted = DB::transaction(function () use ($request, $department): bool {
            $departmentModel = Department::query()
                ->where('organization_id', $request->user()->organization_id)
                ->whereKey($department)
                ->lockForUpdate()
                ->firstOrFail();

            if ($departmentModel->csMembers()->exists() || $departmentModel->conversations()->exists()) {
                return false;
            }

            $departmentModel->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Department cannot be deleted while CS members or conversations reference it.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully.',
        ]);
    }

    private function findForOrganization(Request $request, int $departmentId): Department
    {
        return Department::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereKey($departmentId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function departmentData(Department $department): array
    {
        return $department->only(['id', 'name', 'description', 'active', 'created_at', 'updated_at']);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withoutNullActive(array $data): array
    {
        if (($data['active'] ?? false) === null) {
            unset($data['active']);
        }

        return $data;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? (string) $exception->getCode()) === '23505';
    }

    private function duplicateNameResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'A department with this name already exists in this organization.',
            'errors' => ['name' => ['The name has already been taken.']],
        ], 422);
    }
}
