<?php

namespace App\Services\Vat;

/**
 * Итог документа: сумма без НДС, НДС и сумма с НДС — суммы по позициям.
 */
final class VatTotal
{
    public function __construct(
        public readonly float $net,
        public readonly float $vat,
        public readonly float $gross,
    ) {
    }
}
