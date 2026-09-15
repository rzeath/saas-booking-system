<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveServiceRateRequest;
use App\Http\Requests\ServiceRateIndexRequest;
use App\Http\Resources\ServiceRateResource;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ServiceRateController extends Controller
{
    public function all(
        ServiceRateIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        return ServiceRateResource::collection(
            $this->rates($request->validated(), $tenant)
                ->paginate($request->validated('per_page', 15))
                ->withQueryString(),
        );
    }

    public function index(
        ServiceRateIndexRequest $request,
        int $service,
        int $package,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $this->resolveParents($service, $package, $tenant);

        return ServiceRateResource::collection(
            $this->rates($request->validated(), $tenant, $service, $package)
                ->paginate($request->validated('per_page', 15))
                ->withQueryString(),
        );
    }

    public function store(
        SaveServiceRateRequest $request,
        int $service,
        int $package,
        TenantContext $tenant,
    ): ServiceRateResource {
        $this->resolveParents($service, $package, $tenant);
        $validated = $request->validated();

        $parentErrors = [];

        if ((int) $validated['service_id'] !== $service) {
            $parentErrors['service_id'] = 'The rate must use the service from the URL.';
        }

        if ((int) $validated['package_id'] !== $package) {
            $parentErrors['package_id'] = 'The rate must use the package from the URL.';
        }

        if ($parentErrors !== []) {
            throw ValidationException::withMessages($parentErrors);
        }

        $rate = $tenant->organization()->serviceRates()->create($validated);

        return new ServiceRateResource($rate->load(['eventType', 'service', 'package']));
    }

    public function show(
        int $service,
        int $package,
        int $rate,
        TenantContext $tenant,
    ): ServiceRateResource {
        return new ServiceRateResource($this->resolveRate($service, $package, $rate, $tenant));
    }

    public function update(
        SaveServiceRateRequest $request,
        int $service,
        int $package,
        int $rate,
        TenantContext $tenant,
    ): ServiceRateResource {
        $resolved = $this->resolveRate($service, $package, $rate, $tenant);
        $resolved->update($request->validated());

        return new ServiceRateResource($resolved->refresh()->load(['eventType', 'service', 'package']));
    }

    /** @param array<string, mixed> $validated */
    private function rates(
        array $validated,
        TenantContext $tenant,
        ?int $serviceId = null,
        ?int $packageId = null,
    ): Builder {
        return ServiceRate::query()
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
            ->when($serviceId !== null, fn ($query) => $query->where('service_rates.service_id', $serviceId))
            ->when($packageId !== null, fn ($query) => $query->where('service_rates.package_id', $packageId))
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
            ->orderBy('service_rates.id');
    }

    private function resolveParents(int $service, int $package, TenantContext $tenant): void
    {
        Service::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($service)
            ->firstOrFail();
        Package::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($package)
            ->whereHas('services', fn ($query) => $query
                ->where('services.organization_id', $tenant->organizationId())
                ->whereKey($service))
            ->firstOrFail();
    }

    private function resolveRate(
        int $service,
        int $package,
        int $rate,
        TenantContext $tenant,
    ): ServiceRate {
        $this->resolveParents($service, $package, $tenant);

        return ServiceRate::query()
            ->with(['eventType', 'service', 'package'])
            ->where('organization_id', $tenant->organizationId())
            ->where('service_id', $service)
            ->where('package_id', $package)
            ->whereKey($rate)
            ->firstOrFail();
    }
}
