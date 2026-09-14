<?php

namespace App\Models;

use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    protected $table = 'staff';

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'phone',
        'email',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function bookingServices(): BelongsToMany
    {
        return $this->belongsToMany(
            BookingService::class,
            'booking_service_staff_assignments',
        )->withPivot(['organization_id', 'assigned_by', 'assigned_at']);
    }
}
