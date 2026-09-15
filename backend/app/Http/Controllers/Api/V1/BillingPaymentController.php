<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentIndexRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Billing;
use App\Models\Payment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingPaymentController extends Controller
{
    public function index(
        PaymentIndexRequest $request,
        int $billing,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $resolvedBilling = Billing::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($billing)
            ->firstOrFail();
        $validated = $request->validated();

        $payments = $this->filteredQuery($validated, $tenant)
            ->where('billing_id', $resolvedBilling->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }

    /** @param array<string, mixed> $validated */
    private function filteredQuery(array $validated, TenantContext $tenant): Builder
    {
        return Payment::query()
            ->with([
                'billing:id,billing_number',
                'booking:id,booking_number,status',
                'creator:id,name',
                'quotation:id,quotation_number',
                'voider:id,name',
            ])
            ->where('organization_id', $tenant->organizationId())
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['payment_method'] ?? null, fn ($query, string $method) => $query->where('payment_method', $method))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where('reference_number', 'like', "%{$term}%"))
            ->when($validated['paid_from'] ?? null, fn ($query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($validated['paid_to'] ?? null, fn ($query, string $date) => $query->whereDate('paid_at', '<=', $date));
    }
}
