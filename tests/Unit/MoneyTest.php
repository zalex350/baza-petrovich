<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Единый формат сумм для x-price (docs/UI_INVENTORY.md §3): 1 234,50 BYN,
 * пробелы — неразрывные (U+00A0).
 */
class MoneyTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'целое' => [100, "100,00\u{00A0}BYN"],
            'копейки' => [270.34, "270,34\u{00A0}BYN"],
            'тысячи' => [1234.5, "1\u{00A0}234,50\u{00A0}BYN"],
            'миллион' => [1234567.891, "1\u{00A0}234\u{00A0}567,89\u{00A0}BYN"],
            'строка из БД' => ['99.90', "99,90\u{00A0}BYN"],
            'ноль' => [0, "0,00\u{00A0}BYN"],
        ];
    }

    #[DataProvider('amounts')]
    public function test_formats_amount(float|int|string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::format($amount));
    }

    public function test_spaces_are_non_breaking(): void
    {
        $formatted = Money::format(1234567.5);

        $this->assertStringNotContainsString(' ', $formatted);
        $this->assertSame(3, substr_count($formatted, Money::NBSP));
    }

    public function test_without_currency(): void
    {
        $this->assertSame("1\u{00A0}234,50", Money::format(1234.5, currency: false));
    }

    public function test_empty_amount_is_null(): void
    {
        $this->assertNull(Money::format(null));
        $this->assertNull(Money::format(''));
    }
}
