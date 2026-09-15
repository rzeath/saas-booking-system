<?php

namespace App\Support\Documents;

use InvalidArgumentException;

class PesoFormatter
{
    public function format(string $amount): string
    {
        if (! preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount)) {
            throw new InvalidArgumentException('The amount must be a non-negative decimal value.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return '₱'.$grouped.'.'.str_pad($fraction, 2, '0');
    }
}
