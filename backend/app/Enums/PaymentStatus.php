<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Posted = 'POSTED';
    case Voided = 'VOIDED';
}
