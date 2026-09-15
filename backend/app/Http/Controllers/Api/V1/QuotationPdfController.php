<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Support\Documents\QuotationPdfPresenter;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class QuotationPdfController extends Controller
{
    public function __invoke(
        int $quotation,
        TenantContext $tenant,
        QuotationPdfPresenter $presenter,
    ): Response {
        $resolvedQuotation = Quotation::query()
            ->with('items')
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($quotation)
            ->firstOrFail();

        $filename = $this->filename($resolvedQuotation->quotation_number);

        return Pdf::loadView('pdf.quotation', $presenter->present($resolvedQuotation))
            ->setPaper('a4', 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->download($filename);
    }

    private function filename(string $quotationNumber): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $quotationNumber);
        $safeName = trim((string) $safeName, '-_');

        return ($safeName !== '' ? $safeName : 'quotation').'.pdf';
    }
}
