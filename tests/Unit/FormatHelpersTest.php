<?php

namespace Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Хелперы шаблонов (app/Support/helpers.php): money() — суммы, date_ru() — даты.
 */
class FormatHelpersTest extends TestCase
{
    public function test_money_formats_like_x_price_and_handles_empty(): void
    {
        $this->assertSame("1\u{00A0}234,50\u{00A0}BYN", money(1234.5));
        $this->assertSame('99,90', money('99.90', false));
        $this->assertSame('Цена уточняется', money(null));
        $this->assertSame('—', money('', empty: '—'));
    }

    public function test_date_ru_without_and_with_time(): void
    {
        // В БД и в коде — UTC, людям — по Минску (+3): 14:07 UTC → 17:07
        $date = Carbon::create(2026, 3, 5, 14, 7);

        $this->assertSame('05.03.2026', date_ru($date));
        $this->assertSame('05.03.2026 17:07', date_ru($date, true));
        $this->assertSame('14:07', $date->format('H:i'), 'исходный объект не меняется');
        // 22:30 UTC — уже следующий день по Минску
        $this->assertSame('06.03.2026 01:30', date_ru(Carbon::create(2026, 3, 5, 22, 30), true));
        $this->assertSame('', date_ru(null));
    }
}
