<?php

namespace App\Models;

use Database\Factories\BillingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Billing extends Model
{
    /** @use HasFactory<BillingFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'quotation_id',
        'billing_number',
        'quotation_number',
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at')->orderBy('id');
    }
}
