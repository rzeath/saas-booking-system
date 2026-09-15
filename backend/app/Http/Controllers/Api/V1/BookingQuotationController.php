<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quotations\CreateQuotation;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveDraftQuotationRequest;
use App\Http\Resources\QuotationResource;
use App\Support\Tenancy\TenantContext;

class BookingQuotationController extends Controller
{
    public function store(
        SaveDraftQuotationRequest $request,
        int $booking,
        TenantContext $tenant,
        CreateQuotation $createQuotation,
    ): QuotationResource {
        return new QuotationResource($createQuotation->handle(
            $tenant->user(),
            $booking,
            $request->validated(),
        ));
    }
}
