<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
}
