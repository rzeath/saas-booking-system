<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billings\VoidPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\VoidPaymentRequest;
use App\Http\Resources\PaymentMutationResource;
use App\Support\Tenancy\TenantContext;

class PaymentVoidController extends Controller
{
    public function __invoke(
        VoidPaymentRequest $request,
        int $payment,
        TenantContext $tenant,
        VoidPayment $voidPayment,
    ): PaymentMutationResource {
        $result = $voidPayment->handle(
            $tenant->user(),
            $payment,
            $request->validated('void_reason'),
        );

        return new PaymentMutationResource($result);
    }
}
