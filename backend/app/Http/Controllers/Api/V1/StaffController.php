<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\SaveStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffController extends Controller
{
    public function index(
        MasterDataIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $status = $validated['status'];

        $staff = Staff::query()
            ->where('organization_id', $tenant->organizationId())
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($search, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return StaffResource::collection($staff);
    }

    public function store(SaveStaffRequest $request, TenantContext $tenant): StaffResource
    {
        $staff = $tenant->organization()->staff()->create($request->validated());

        return new StaffResource($staff);
    }

    public function show(int $staff, TenantContext $tenant): StaffResource
    {
        return new StaffResource($this->resolve($staff, $tenant));
    }

    public function update(
        SaveStaffRequest $request,
        int $staff,
        TenantContext $tenant,
    ): StaffResource {
        $resolvedStaff = $this->resolve($staff, $tenant);
        $resolvedStaff->update($request->validated());

        return new StaffResource($resolvedStaff->refresh());
    }

    private function resolve(int $staff, TenantContext $tenant): Staff
    {
        return Staff::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($staff)
            ->firstOrFail();
    }
}
