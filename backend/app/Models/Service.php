<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $fillable = ['name', 'total_units', 'is_active'];

    protected function casts(): array
    {
        return ['total_units' => 'integer', 'is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'service_package')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    public function serviceRates(): HasMany
    {
        return $this->hasMany(ServiceRate::class);
    }

    public function bookingServices(): HasMany
    {
        return $this->hasMany(BookingService::class);
    }
}
