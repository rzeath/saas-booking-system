<?php

namespace App\Models;

use Database\Factories\BookingServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingService extends Model
{
    /** @use HasFactory<BookingServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'service_id',
        'package_id',
        'start_at',
        'end_at',
        'duration_minutes',
        'quantity',
        'service_name',
        'package_name',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
