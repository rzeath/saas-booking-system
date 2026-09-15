<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BillingIndexRequest;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingController extends Controller
{
    public function index(
        BillingIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();

        $billings = Billing::query()
            ->with([
                'booking:id,booking_number,status',
                'payments:id,billing_id,amount,status',
            ])
            ->where('organization_id', $tenant->organizationId())
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('billing_number', 'like', "%{$term}%")
                    ->orWhere('quotation_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%");
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return BillingResource::collection($billings);
    }

    public function show(int $billing, TenantContext $tenant): BillingResource
    {
        return new BillingResource($this->resolve($billing, $tenant));
    }

    private function resolve(int $billing, TenantContext $tenant): Billing
    {
        return Billing::query()
            ->with([
                'booking:id,booking_number,status',
                'items',
                'payments:id,billing_id,amount,status',
            ])
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($billing)
            ->firstOrFail();
    }
}
