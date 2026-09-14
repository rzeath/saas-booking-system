<?php

namespace App\Models;

use Database\Factories\BusinessSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    /** @use HasFactory<BusinessSettingFactory> */
    use HasFactory;

    public const DEFAULT_CURRENCY = 'PHP';

    public const DEFAULT_BOOKING_PREFIX = 'BK';

    public const DEFAULT_QUOTATION_PREFIX = 'QT';

    public const DEFAULT_BILLING_PREFIX = 'INV';

    protected $fillable = [
        'display_name',
        'email',
        'phone',
        'address',
        'currency',
        'booking_prefix',
        'quotation_prefix',
        'billing_prefix',
    ];

    /** @return array<string, string> */
    public static function defaults(string $displayName): array
    {
        return [
            'display_name' => $displayName,
            'currency' => self::DEFAULT_CURRENCY,
            'booking_prefix' => self::DEFAULT_BOOKING_PREFIX,
            'quotation_prefix' => self::DEFAULT_QUOTATION_PREFIX,
            'billing_prefix' => self::DEFAULT_BILLING_PREFIX,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
