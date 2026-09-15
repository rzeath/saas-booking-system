<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\SavePackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    public function index(
        MasterDataIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();

        $packages = Package::query()
            ->with('services')
            ->where('organization_id', $tenant->organizationId())
            ->when($validated['status'] !== 'all', fn ($query) => $query->where('is_active', $validated['status'] === 'active'))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function store(SavePackageRequest $request, TenantContext $tenant): PackageResource
    {
        $package = $tenant->organization()->packages()->create($request->validated());

        return new PackageResource($package->load('services'));
    }

    public function show(int $package, TenantContext $tenant): PackageResource
    {
        return new PackageResource($this->resolvePackage($package, $tenant));
    }

    public function update(SavePackageRequest $request, int $package, TenantContext $tenant): PackageResource
    {
        $resolved = $this->resolvePackage($package, $tenant);
        $resolved->update($request->validated());

        return new PackageResource($resolved->refresh()->load('services'));
    }

    private function resolvePackage(int $id, TenantContext $tenant): Package
    {
        return Package::query()
            ->with('services')
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($id)
            ->firstOrFail();
    }
}
