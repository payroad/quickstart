<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Payroad\Domain\Money\Currency;

final class KnownCurrencies
{
    private const MAP = [
        // Fiat
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CAD' => 2, 'AUD' => 2,
        'CHF' => 2, 'CNY' => 2, 'HKD' => 2, 'SGD' => 2, 'SEK' => 2,
        'NOK' => 2, 'DKK' => 2, 'PLN' => 2, 'CZK' => 2, 'HUF' => 2,
        'JPY' => 0, 'KRW' => 0, 'CLP' => 0,
        'KWD' => 3, 'BHD' => 3, 'JOD' => 3,
        // Crypto
        'BTC'  => 8, 'LTC'  => 8, 'BCH' => 8, 'DOGE' => 8, 'BNB' => 8,
        'ETH'  => 18, 'DAI' => 18, 'MATIC' => 18,
        'XRP'  => 6, 'USDT' => 6, 'USDC' => 6,
        'XLM'  => 7,
        'ADA'  => 6,
        'SOL'  => 9,
    ];

    public static function get(string $code): Currency
    {
        $code = strtoupper($code);

        if (!isset(self::MAP[$code])) {
            throw new \InvalidArgumentException(
                "Unknown currency \"{$code}\". Add it to App\\Infrastructure\\KnownCurrencies::MAP."
            );
        }

        return new Currency($code, self::MAP[$code]);
    }
}
