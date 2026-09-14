<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveServiceRateRequest;
use App\Http\Requests\ServiceRateIndexRequest;
use App\Http\Resources\ServiceRateResource;
use App\Models\ServiceRate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceRateController extends Controller
{
    public function index(ServiceRateIndexRequest $request, TenantContext $tenant): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $rates = ServiceRate::query()
            ->select('service_rates.*')
            ->with(['eventType', 'service', 'package'])
            ->join('event_types', function ($join): void {
                $join->on('event_types.id', '=', 'service_rates.event_type_id')
                    ->on('event_types.organization_id', '=', 'service_rates.organization_id');
            })
            ->join('packages', function ($join): void {
                $join->on('packages.id', '=', 'service_rates.package_id')
                    ->on('packages.organization_id', '=', 'service_rates.organization_id');
            })
            ->join('services', function ($join): void {
                $join->on('services.id', '=', 'service_rates.service_id')
                    ->on('services.organization_id', '=', 'service_rates.organization_id');
            })
            ->where('service_rates.organization_id', $tenant->organizationId())
            ->when($validated['status'] !== 'all', fn ($query) => $query->where('service_rates.is_active', $validated['status'] === 'active'))
            ->when($validated['event_type_id'] ?? null, fn ($query, int $id) => $query->where('service_rates.event_type_id', $id))
            ->when($validated['package_id'] ?? null, fn ($query, int $id) => $query->where('service_rates.package_id', $id))
            ->when($validated['service_id'] ?? null, fn ($query, int $id) => $query->where('service_rates.service_id', $id))
            ->when($validated['duration_minutes'] ?? null, fn ($query, int $duration) => $query->where('service_rates.duration_minutes', $duration))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('services.name', 'like', "%{$term}%")
                    ->orWhere('packages.name', 'like', "%{$term}%")
                    ->orWhere('event_types.name', 'like', "%{$term}%");
            }))
            ->orderBy('services.name')
            ->orderBy('packages.name')
            ->orderBy('event_types.name')
            ->orderBy('service_rates.duration_minutes')
            ->orderBy('service_rates.id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return ServiceRateResource::collection($rates);
    }

    public function store(SaveServiceRateRequest $request, TenantContext $tenant): ServiceRateResource
    {
        $rate = $tenant->organization()->serviceRates()->create($request->validated());

        return new ServiceRateResource($rate->load(['eventType', 'service', 'package']));
    }

    public function show(int $serviceRate, TenantContext $tenant): ServiceRateResource
    {
        return new ServiceRateResource($this->resolve($serviceRate, $tenant));
    }

    public function update(SaveServiceRateRequest $request, int $serviceRate, TenantContext $tenant): ServiceRateResource
    {
        $resolved = $this->resolve($serviceRate, $tenant);
        $resolved->update($request->validated());

        return new ServiceRateResource($resolved->refresh()->load(['eventType', 'service', 'package']));
    }

    private function resolve(int $id, TenantContext $tenant): ServiceRate
    {
        return ServiceRate::query()
            ->with(['eventType', 'service', 'package'])
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($id)
            ->firstOrFail();
    }
}
