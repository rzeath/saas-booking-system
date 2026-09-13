<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'name',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function businessSetting(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function serviceRates(): HasMany
    {
        return $this->hasMany(ServiceRate::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }
}
