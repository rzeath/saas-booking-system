<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quotations\AcceptQuotation;
use App\Actions\Quotations\CancelQuotation;
use App\Actions\Quotations\RejectQuotation;
use App\Actions\Quotations\SendQuotation;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Support\Tenancy\TenantContext;

class QuotationStatusController extends Controller
{
    public function send(
        int $quotation,
        TenantContext $tenant,
        SendQuotation $sendQuotation,
    ): QuotationResource {
        return new QuotationResource($sendQuotation->handle($tenant->user(), $quotation));
    }

    public function accept(
        int $quotation,
        TenantContext $tenant,
        AcceptQuotation $acceptQuotation,
    ): QuotationResource {
        return new QuotationResource($acceptQuotation->handle($tenant->user(), $quotation));
    }

    public function reject(
        int $quotation,
        TenantContext $tenant,
        RejectQuotation $rejectQuotation,
    ): QuotationResource {
        return new QuotationResource($rejectQuotation->handle($tenant->user(), $quotation));
    }

    public function cancel(
        int $quotation,
        TenantContext $tenant,
        CancelQuotation $cancelQuotation,
    ): QuotationResource {
        return new QuotationResource($cancelQuotation->handle($tenant->user(), $quotation));
    }
}
