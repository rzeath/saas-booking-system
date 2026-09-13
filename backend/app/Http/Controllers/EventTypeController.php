<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\SaveEventTypeRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventTypeController extends Controller
{
    public function index(
        MasterDataIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $status = $validated['status'];

        $eventTypes = EventType::query()
            ->where('organization_id', $tenant->organizationId())
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($search, fn ($query, string $term) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return EventTypeResource::collection($eventTypes);
    }

    public function store(
        SaveEventTypeRequest $request,
        TenantContext $tenant,
    ): EventTypeResource {
        $eventType = $tenant->organization()->eventTypes()->create($request->validated());

        return new EventTypeResource($eventType);
    }

    public function show(int $eventType, TenantContext $tenant): EventTypeResource
    {
        return new EventTypeResource($this->resolve($eventType, $tenant));
    }

    public function update(
        SaveEventTypeRequest $request,
        int $eventType,
        TenantContext $tenant,
    ): EventTypeResource {
        $resolvedEventType = $this->resolve($eventType, $tenant);
        $resolvedEventType->update($request->validated());

        return new EventTypeResource($resolvedEventType->refresh());
    }

    private function resolve(int $eventType, TenantContext $tenant): EventType
    {
        return EventType::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($eventType)
            ->firstOrFail();
    }
}
