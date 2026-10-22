<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Единый формат сумм для x-price (docs/UI_INVENTORY.md §3).
 */
class MoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'целое' => [100, '100.00 BYN'],
            'копейки' => [270.34, '270.34 BYN'],
            'тысячи' => [1234.5, '1 234.50 BYN'],
            'миллион' => [1234567.891, '1 234 567.89 BYN'],
            'строка из БД' => ['99.90', '99.90 BYN'],
            'ноль' => [0, '0.00 BYN'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_formats_amount(float|int|string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::format($amount));
    }

    public function test_without_currency(): void
    {
        $this->assertSame('1 234.50', Money::format(1234.5, currency: false));
    }

    public function test_empty_amount_is_null(): void
    {
        $this->assertNull(Money::format(null));
        $this->assertNull(Money::format(''));
    }
}
