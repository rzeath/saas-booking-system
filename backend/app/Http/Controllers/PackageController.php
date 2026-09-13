<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\SavePackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PackageController extends Controller
{
    public function index(
        MasterDataIndexRequest $request,
        int $service,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $resolvedService = $this->resolveService($service, $tenant);
        $validated = $request->validated();

        $packages = $resolvedService->packages()
            ->with('service')
            ->when($validated['status'] !== 'all', fn ($query) => $query->where('is_active', $validated['status'] === 'active'))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function store(SavePackageRequest $request, int $service, TenantContext $tenant): PackageResource
    {
        $resolvedService = $this->resolveService($service, $tenant);
        $package = new Package($request->validated());
        $package->organization()->associate($tenant->organization());
        $package->service()->associate($resolvedService);
        $package->save();

        return new PackageResource($package->load('service'));
    }

    public function show(int $package, TenantContext $tenant): PackageResource
    {
        return new PackageResource($this->resolvePackage($package, $tenant));
    }

    public function update(SavePackageRequest $request, int $package, TenantContext $tenant): PackageResource
    {
        $resolved = $this->resolvePackage($package, $tenant);
        $resolved->update($request->validated());

        return new PackageResource($resolved->refresh()->load('service'));
    }

    private function resolveService(int $id, TenantContext $tenant): Service
    {
        return Service::query()->where('organization_id', $tenant->organizationId())->whereKey($id)->firstOrFail();
    }

    private function resolvePackage(int $id, TenantContext $tenant): Package
    {
        return Package::query()
            ->with('service')
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($id)
            ->firstOrFail();
    }
}
