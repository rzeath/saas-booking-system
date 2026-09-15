<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Support\Documents\BillingPdfPresenter;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class BillingPdfController extends Controller
{
    public function __invoke(
        int $billing,
        TenantContext $tenant,
        BillingPdfPresenter $presenter,
    ): Response {
        $resolvedBilling = Billing::query()
            ->with(['items', 'payments'])
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($billing)
            ->firstOrFail();

        $filename = $this->filename($resolvedBilling->billing_number);

        return Pdf::loadView('pdf.billing', $presenter->present($resolvedBilling))
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->download($filename);
    }

    private function filename(string $billingNumber): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $billingNumber);
        $safeName = trim((string) $safeName, '-_');

        return ($safeName !== '' ? $safeName : 'billing').'.pdf';
    }
}
