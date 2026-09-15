<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => QuotationStatus::Draft,
    ];

    protected $fillable = [
        'booking_id',
        'quotation_number',
        'status',
        'valid_until',
        'sent_at',
        'accepted_at',
        'closed_at',
        'business_display_name',
        'business_email',
        'business_phone',
        'business_address',
        'business_logo_path',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'event_type_name',
        'event_name',
        'event_date',
        'venue_name',
        'venue_address',
        'contact_person',
        'contact_number',
        'subtotal',
        'transportation_fee',
        'crew_meal_fee',
        'discount_amount',
        'total',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'valid_until' => 'immutable_date:Y-m-d',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'event_date' => 'immutable_date:Y-m-d',
            'subtotal' => 'decimal:2',
            'transportation_fee' => 'decimal:2',
            'crew_meal_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
