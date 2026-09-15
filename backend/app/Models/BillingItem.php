<?php

namespace App\Models;

use Database\Factories\BillingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingItem extends Model
{
    /** @use HasFactory<BillingItemFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'quotation_id',
        'quotation_item_id',
        'service_name',
        'package_name',
        'start_at',
        'end_at',
        'duration_minutes',
        'quantity',
        'unit_rate',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'quantity' => 'integer',
            'unit_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }
}
