<?php

namespace App\Enums;

enum QuotationStatus: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case Outdated = 'OUTDATED';
}
