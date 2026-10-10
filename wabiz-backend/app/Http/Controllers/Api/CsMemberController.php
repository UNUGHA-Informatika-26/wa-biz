<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListCsMembersRequest;
use App\Http\Requests\Api\StoreCsMemberRequest;
use App\Http\Requests\Api\UpdateCsMemberRequest;
use App\Models\CsMember;
use App\Models\Department;
use App\Models\User;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CsMemberController extends Controller
{
    public function index(ListCsMembersRequest $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $validated = $request->validated();

        $query = CsMember::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with([
                'user:id,name,email',
                'department:id,name,active',
            ]);

        if (isset($validated['active']) && $validated['active'] !== null) {
            $query->where('active', filter_var($validated['active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (array_key_exists('department_id', $validated)) {
            $validated['department_id'] === null
                ? $query->whereNull('department_id')
                : $query->where('department_id', $validated['department_id']);
        }

        if (filled($validated['search'] ?? null)) {
            $search = $validated['search'];
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('display_name', 'ILIKE', '%'.$search.'%')
                    ->orWhere('phone_number', 'ILIKE', '%'.$search.'%');
            });
        }

        $members = $query
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $members->getCollection()
                ->map(fn (CsMember $member): array => $this->memberData($member))
                ->values(),
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'per_page' => $members->perPage(),
                'total' => $members->total(),
            ],
        ]);
    }

    public function store(StoreCsMemberRequest $request, IndonesianPhoneNumberNormalizer $phoneNormalizer): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $organizationId = $request->user()->organization_id;
        $validated = $this->withoutNullActive($request->validated());
        if (filled($validated['phone_number'] ?? null)) {
            $validated['phone_number'] = $phoneNormalizer->canonicalize($validated['phone_number']);
            if (preg_match('/^\+[1-9][0-9]{7,14}$/', $validated['phone_number']) !== 1) {
                return response()->json(['success' => false, 'message' => 'A valid international WhatsApp number is required.', 'errors' => ['phone_number' => ['Use an international number such as +6281234567890.']]], 422);
            }
        }

        try {
            $member = DB::transaction(function () use ($organizationId, $validated): ?CsMember {
                $user = isset($validated['user_id'])
                    ? User::query()->where('organization_id', $organizationId)->whereKey($validated['user_id'])->lockForUpdate()->firstOrFail()
                    : null;

                $department = null;
                if (($validated['department_id'] ?? null) !== null) {
                    $department = Department::query()
                        ->where('organization_id', $organizationId)
                        ->where('active', true)
                        ->whereKey($validated['department_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($user && $this->membershipExists($organizationId, $user->id, $department?->id)) {
                    return null;
                }
                if (! $user && filled($validated['phone_number'] ?? null)
                    && CsMember::query()->where('organization_id', $organizationId)->where('phone_number', $validated['phone_number'])->exists()) {
                    return null;
                }

                $member = new CsMember;
                $member->organization_id = $organizationId;
                if ($user) {
                    $member->user()->associate($user);
                }
                $member->department()->associate($department);
                $member->fill(collect($validated)->only(['display_name', 'phone_number', 'active'])->all());
                $member->save();

                return $member->load(['user:id,name,email', 'department:id,name,active']);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->duplicateMembershipResponse();
        }

        if (! $member) {
            return $this->duplicateMembershipResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $this->memberData($member),
        ], 201);
    }

    public function show(Request $request, int $csMember): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $member = $this->findForOrganization($request, $csMember)
            ->load(['user:id,name,email', 'department:id,name,active']);

        return response()->json([
            'success' => true,
            'data' => $this->memberData($member),
        ]);
    }

    public function linkableUsers(Request $request, int $csMember): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $member = $this->findForOrganization($request, $csMember);
        $organizationId = (int) $request->user()->organization_id;

        $users = User::query()
            ->where('organization_id', $organizationId)
            ->where('role', 'staff')
            ->where(function ($query) use ($member, $organizationId): void {
                $query->whereKey($member->user_id ?? 0)
                    ->orWhereDoesntHave('csMembers', function ($membership) use ($member, $organizationId): void {
                        $membership->where('organization_id', $organizationId)
                            ->whereKeyNot($member->id)
                            ->when($member->department_id === null,
                                fn ($query) => $query->whereNull('department_id'),
                                fn ($query) => $query->where('department_id', $member->department_id));
                    });
            })
            ->orderBy('name')->orderBy('id')->limit(100)
            ->get(['id', 'name', 'email']);

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function update(UpdateCsMemberRequest $request, int $csMember, IndonesianPhoneNumberNormalizer $phoneNormalizer): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $organizationId = $request->user()->organization_id;
        $validated = $this->withoutNullActive($request->validated());
        if (filled($validated['phone_number'] ?? null)) {
            $validated['phone_number'] = $phoneNormalizer->canonicalize($validated['phone_number']);
            if (preg_match('/^\+[1-9][0-9]{7,14}$/', $validated['phone_number']) !== 1) {
                return response()->json(['success' => false, 'message' => 'A valid international WhatsApp number is required.', 'errors' => ['phone_number' => ['Use an international number such as +6281234567890.']]], 422);
            }
        }

        try {
            $member = DB::transaction(function () use ($csMember, $organizationId, $validated): ?CsMember {
                $member = CsMember::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($csMember)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (array_key_exists('user_id', $validated)) {
                    $user = User::query()
                        ->where('organization_id', $organizationId)
                        ->where('role', 'staff')
                        ->whereKey($validated['user_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    abort_if($member->user_id !== null && (int) $member->user_id !== (int) $user->id, 409, 'A linked CS identity cannot be replaced.');
                    $targetDepartmentId = array_key_exists('department_id', $validated) ? $validated['department_id'] : $member->department_id;
                    if ($this->membershipExists($organizationId, (int) $user->id, $targetDepartmentId, $member->id)) {
                        return null;
                    }
                    $member->user()->associate($user);
                }

                if (array_key_exists('department_id', $validated)) {
                    $department = null;
                    if ($validated['department_id'] !== null) {
                        $department = Department::query()
                            ->where('organization_id', $organizationId)
                            ->where('active', true)
                            ->whereKey($validated['department_id'])
                            ->lockForUpdate()
                            ->firstOrFail();
                    }

                    if ($member->user_id !== null && $this->membershipExists($organizationId, $member->user_id, $department?->id, $member->id)) {
                        return null;
                    }

                    $member->department()->associate($department);
                }

                $member->fill(collect($validated)->only(['display_name', 'phone_number', 'active'])->all());
                $member->save();

                return $member->refresh()->load(['user:id,name,email', 'department:id,name,active']);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->duplicateMembershipResponse();
        }

        if (! $member) {
            return $this->duplicateMembershipResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $this->memberData($member),
        ]);
    }

    public function destroy(Request $request, int $csMember): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        $deleted = DB::transaction(function () use ($request, $csMember): bool {
            $member = CsMember::query()
                ->where('organization_id', $request->user()->organization_id)
                ->whereKey($csMember)
                ->lockForUpdate()
                ->firstOrFail();

            if ($member->conversations()->exists()) {
                return false;
            }

            $member->delete();

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'success' => false,
                'message' => 'CS member cannot be deleted while assigned conversations reference it.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'CS member deleted successfully.',
        ]);
    }

    private function findForOrganization(Request $request, int $csMemberId): CsMember
    {
        return CsMember::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereKey($csMemberId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function memberData(CsMember $member): array
    {
        return [
            'id' => $member->id,
            'display_name' => $member->display_name,
            'phone_number' => $member->phone_number,
            'active' => $member->active,
            'department' => $member->department ? [
                'id' => $member->department->id,
                'name' => $member->department->name,
                'active' => $member->department->active,
            ] : null,
            'user' => $member->user ? [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
            ] : null,
        ];
    }

    private function membershipExists(int $organizationId, int $userId, ?int $departmentId, ?int $exceptId = null): bool
    {
        return CsMember::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->when($departmentId === null,
                fn ($query) => $query->whereNull('department_id'),
                fn ($query) => $query->where('department_id', $departmentId))
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
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

    private function duplicateMembershipResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This user already has a CS membership for that department.',
            'errors' => ['user_id' => ['The user already has a CS membership for that department.']],
        ], 422);
    }
}
