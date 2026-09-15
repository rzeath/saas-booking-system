<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billings\RecordPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPaymentRequest;
use App\Http\Resources\PaymentMutationResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class QuotationPaymentController extends Controller
{
    public function store(
        RecordPaymentRequest $request,
        int $quotation,
        TenantContext $tenant,
        RecordPayment $recordPayment,
    ): JsonResponse {
        $result = $recordPayment->handle(
            $tenant->user(),
            $quotation,
            $request->validated(),
        );

        return (new PaymentMutationResource($result))
            ->response()
            ->setStatusCode(201);
    }
}
