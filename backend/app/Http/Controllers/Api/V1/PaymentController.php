<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentIndexRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentController extends Controller
{
    public function index(
        PaymentIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();

        $payments = Payment::query()
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
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('reference_number', 'like', "%{$term}%")
                    ->orWhereHas('billing', fn ($query) => $query->where('billing_number', 'like', "%{$term}%"))
                    ->orWhereHas('quotation', fn ($query) => $query->where('quotation_number', 'like', "%{$term}%"));
            }))
            ->when($validated['paid_from'] ?? null, fn ($query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($validated['paid_to'] ?? null, fn ($query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }
}
