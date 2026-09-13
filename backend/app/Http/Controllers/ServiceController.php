<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\SaveServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(MasterDataIndexRequest $request, TenantContext $tenant): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $services = Service::query()
            ->where('organization_id', $tenant->organizationId())
            ->when($validated['status'] !== 'all', fn ($query) => $query->where('is_active', $validated['status'] === 'active'))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ServiceResource::collection($services);
    }

    public function store(SaveServiceRequest $request, TenantContext $tenant): ServiceResource
    {
        return new ServiceResource($tenant->organization()->services()->create($request->validated()));
    }

    public function show(int $service, TenantContext $tenant): ServiceResource
    {
        return new ServiceResource($this->resolve($service, $tenant));
    }

    public function update(SaveServiceRequest $request, int $service, TenantContext $tenant): ServiceResource
    {
        $resolved = $this->resolve($service, $tenant);
        $resolved->update($request->validated());

        return new ServiceResource($resolved->refresh());
    }

    private function resolve(int $id, TenantContext $tenant): Service
    {
        return Service::query()->where('organization_id', $tenant->organizationId())->whereKey($id)->firstOrFail();
    }
}
