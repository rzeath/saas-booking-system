<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case GCash = 'GCASH';
    case BankTransfer = 'BANK_TRANSFER';
    case Check = 'CHECK';
}
