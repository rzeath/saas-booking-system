<?php

namespace App\Models;

use App\Enums\ThemeAccent;
use Database\Factories\BusinessSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    /** @use HasFactory<BusinessSettingFactory> */
    use HasFactory;

    public const DEFAULT_BOOKING_PREFIX = 'BK';

    public const DEFAULT_QUOTATION_PREFIX = 'QT';

    public const DEFAULT_BILLING_PREFIX = 'INV';

    protected $attributes = [
        'theme_accent' => ThemeAccent::Plum->value,
    ];

    protected $fillable = [
        'display_name',
        'email',
        'phone',
        'address',
        'logo_path',
        'theme_accent',
        'booking_prefix',
        'quotation_prefix',
        'billing_prefix',
    ];

    /** @return array<string, string> */
    public static function defaults(string $displayName): array
    {
        return [
            'display_name' => $displayName,
            'theme_accent' => ThemeAccent::Plum->value,
            'booking_prefix' => self::DEFAULT_BOOKING_PREFIX,
            'quotation_prefix' => self::DEFAULT_QUOTATION_PREFIX,
            'billing_prefix' => self::DEFAULT_BILLING_PREFIX,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'theme_accent' => ThemeAccent::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
