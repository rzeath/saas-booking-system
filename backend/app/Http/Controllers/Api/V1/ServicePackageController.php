<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Services\UpdateServicePackages;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\UpdateServicePackagesRequest;
use App\Http\Resources\PackageResource;
use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServicePackageController extends Controller
{
    public function index(
        MasterDataIndexRequest $request,
        int $service,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $resolved = $this->resolve($service, $tenant);
        $validated = $request->validated();
        $packages = $resolved->packages()
            ->with('services')
            ->when($validated['status'] !== 'all', fn ($query) => $query->where('packages.is_active', $validated['status'] === 'active'))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where('packages.name', 'like', "%{$term}%"))
            ->orderBy('packages.name')
            ->orderBy('packages.id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PackageResource::collection($packages);
    }

    public function update(
        UpdateServicePackagesRequest $request,
        int $service,
        TenantContext $tenant,
        UpdateServicePackages $updateServicePackages,
    ): AnonymousResourceCollection {
        $updated = $updateServicePackages->handle(
            $this->resolve($service, $tenant),
            $request->validated('package_ids'),
        );

        return PackageResource::collection($updated->packages);
    }

    private function resolve(int $id, TenantContext $tenant): Service
    {
        return Service::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($id)
            ->firstOrFail();
    }
}
