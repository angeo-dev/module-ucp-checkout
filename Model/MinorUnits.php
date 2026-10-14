<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Model;

/**
 * Amounts in UCP are integers in the currency's minor unit (spec
 * common/types/amount.json). The exponent comes from ISO 4217; hardcoding
 * x100 would be wrong for JPY (0) and KWD (3).
 */
class MinorUnits
{
    /** ISO 4217 currencies whose minor unit is not 2. */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    /**
     * Major units (Magento's float amounts) to minor-unit integers.
     */
    public function toMinor(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** $this->exponent($currency)));
    }
}
