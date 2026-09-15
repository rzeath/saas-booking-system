<?php

namespace App\Models;

use Database\Factories\BookingServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingService extends Model
{
    /** @use HasFactory<BookingServiceFactory> */
    use HasFactory;

    public const int MIN_DURATION_MINUTES = 1;

    public const int MAX_DURATION_MINUTES = 10_080;

    protected $fillable = [
        'organization_id',
        'service_id',
        'package_id',
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

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(BookingServiceStaffAssignment::class);
    }

    public function assignedStaff(): BelongsToMany
    {
        return $this->belongsToMany(
            Staff::class,
            'booking_service_staff_assignments',
        )->withPivot(['organization_id', 'assigned_by', 'assigned_at']);
    }
}
