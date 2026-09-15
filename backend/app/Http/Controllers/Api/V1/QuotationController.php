<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quotations\UpdateDraftQuotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuotationIndexRequest;
use App\Http\Requests\SaveDraftQuotationRequest;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\QuotationSummaryResource;
use App\Models\Quotation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuotationController extends Controller
{
    public function index(
        QuotationIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();

        $quotations = Quotation::query()
            ->with('booking:id,booking_number,status')
            ->where('organization_id', $tenant->organizationId())
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('quotation_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%");
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return QuotationSummaryResource::collection($quotations);
    }

    public function show(int $quotation, TenantContext $tenant): QuotationResource
    {
        return new QuotationResource($this->resolve($quotation, $tenant));
    }

    public function update(
        SaveDraftQuotationRequest $request,
        int $quotation,
        TenantContext $tenant,
        UpdateDraftQuotation $updateDraftQuotation,
    ): QuotationResource {
        $resolvedQuotation = $updateDraftQuotation->handle(
            $tenant->user(),
            $quotation,
            $request->validated(),
        );

        return new QuotationResource($resolvedQuotation->loadMissing('booking'));
    }

    private function resolve(int $quotation, TenantContext $tenant): Quotation
    {
        return Quotation::query()
            ->with(['booking:id,booking_number,status', 'items'])
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($quotation)
            ->firstOrFail();
    }
}
