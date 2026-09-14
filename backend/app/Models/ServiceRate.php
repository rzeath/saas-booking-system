<?php

namespace App\Models;

use Database\Factories\ServiceRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRate extends Model
{
    /** @use HasFactory<ServiceRateFactory> */
    use HasFactory;

    protected $fillable = ['event_type_id', 'service_id', 'package_id', 'duration_minutes', 'unit_rate', 'is_active'];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'unit_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
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
