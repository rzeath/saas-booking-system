<?php

namespace App\Support\Documents;

use App\Models\Quotation;
use App\Models\QuotationItem;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class QuotationPdfPresenter
{
    public function __construct(private readonly PesoFormatter $pesoFormatter) {}

    /** @return array<string, mixed> */
    public function present(Quotation $quotation): array
    {
        return [
            'quotation' => $quotation,
            'logoDataUri' => $this->logoDataUri($quotation),
            'statusLabel' => ucfirst(strtolower($quotation->status->value)),
            'issueDate' => $this->formatDate($quotation->created_at),
            'validUntil' => $this->formatDate($quotation->valid_until),
            'items' => $quotation->items->map(fn (QuotationItem $item): array => [
                'serviceName' => $item->service_name,
                'packageName' => $item->package_name,
                'schedule' => $this->formatSchedule($item),
                'duration' => $this->formatDuration($item->duration_minutes),
                'quantity' => $item->quantity,
                'unitRate' => $this->pesoFormatter->format($item->unit_rate),
                'lineTotal' => $this->pesoFormatter->format($item->line_total),
            ])->all(),
            'money' => [
                'subtotal' => $this->pesoFormatter->format($quotation->subtotal),
                'transportationFee' => $this->pesoFormatter->format($quotation->transportation_fee),
                'crewMealFee' => $this->pesoFormatter->format($quotation->crew_meal_fee),
                'discountAmount' => $this->pesoFormatter->format($quotation->discount_amount),
                'total' => $this->pesoFormatter->format($quotation->total),
            ],
        ];
    }

    private function formatDate(?DateTimeInterface $date): string
    {
        return $date?->format('F j, Y') ?? 'Not specified';
    }

    private function formatSchedule(QuotationItem $item): string
    {
        $start = $item->start_at;
        $end = $item->end_at;

        if ($start->isSameDay($end)) {
            return $start->format('M j, Y · g:i A').' - '.$end->format('g:i A');
        }

        return $start->format('M j, Y · g:i A').' - '.$end->format('M j, Y · g:i A');
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        return $minutes.' minutes';
    }

    private function logoDataUri(Quotation $quotation): ?string
    {
        $path = $quotation->business_logo_path;
        $prefix = "business-logos/{$quotation->organization_id}/";

        if ($path === null || ! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                return null;
            }

            $mimeType = $this->supportedImageMimeType($disk, $path);

            if ($mimeType === null) {
                return null;
            }

            return 'data:'.$mimeType.';base64,'.base64_encode($disk->get($path));
        } catch (Throwable) {
            return null;
        }
    }

    private function supportedImageMimeType(FilesystemAdapter $disk, string $path): ?string
    {
        $mimeType = $disk->mimeType($path);

        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            ? $mimeType
            : null;
    }
}
