<?php

namespace Tests\Feature\CatalogImport;

use App\Services\CatalogImport\CatalogMapping;
use Tests\TestCase;

/**
 * Маппинги database/data/catalog/*.php (CatalogMapping): бренды, категории, характеристики.
 */
class CatalogMappingTest extends TestCase
{
    private CatalogMapping $map;

    protected function setUp(): void
    {
        parent::setUp();

        $this->map = CatalogMapping::load(database_path('data/catalog'));
    }

    public function test_brands_are_canonical(): void
    {
        $this->assertSame('LEDEME', $this->map->brand('Ledeme'));
        $this->assertSame('LEDEME', $this->map->brand(' LEDEME '));
        $this->assertSame('WISENT', $this->map->brand('WIZENT'));
        $this->assertSame('ZEISSLER', $this->map->brand('ZEISSER'));
        $this->assertSame('TIM', $this->map->brand('TIM Thermo'));
        $this->assertSame('TIM', $this->map->brand('AquaTIM'));
        $this->assertSame('НПО “Эрготех”', $this->map->brand('НПО “Эрготех”'));
        // Неизвестный — как есть, пустой — пустой
        $this->assertSame('Новый Бренд', $this->map->brand('Новый  Бренд'));
        $this->assertNull($this->map->brand(''));
        $this->assertNull($this->map->brand(null));

        $this->assertSame('Thermo', $this->map->brandSeries('TIM Thermo'));
        $this->assertSame('AquaTIM', $this->map->brandSeries('aquatim'));
        $this->assertNull($this->map->brandSeries('TIM'));
    }

    public function test_categories_map_novelty_and_rules(): void
    {
        $this->assertSame(['Смесители', 'Для кухни'], $this->map->mappedPath('на кухню'));
        $this->assertSame(['Смесители', 'Для кухни'], $this->map->mappedPath('КУХОННЫЕ'));
        $this->assertSame(['Смесители', 'Для умывальника'], $this->map->mappedPath('для умывальника'));
        $this->assertSame(['Смесители', 'Запчасти'], $this->map->mappedPath('картриджи'));
        $this->assertSame(['Аксессуары для ванной', 'Ёршики'], $this->map->mappedPath('туалетные ершики'));
        // Нет в маппинге — остаётся в своей категории
        $this->assertNull($this->map->mappedPath('Сифоны'));

        $this->assertTrue($this->map->isNovelty('новинки'));
        $this->assertFalse($this->map->isNovelty('Сифоны'));

        $this->assertSame([['Смесители', 'Для кухни'], 'название: /смесител\w*.{0,40}кух|кухонн\w* смесител/'], $this->map->ruleFor('Смеситель для кухни с гайкой', null));
        $this->assertSame(['Аксессуары для ванной', 'Крючки'], $this->map->ruleFor(null, 'Крючки')[0]);
        // Ринзер — к мойкам, хотя в названии есть «стаканов»
        $this->assertSame(['Мойки и умывальники', 'Аксессуары для моек'], $this->map->ruleFor('Ринзер (ополаскиватель) для стаканов', null)[0]);
        $this->assertNull($this->map->ruleFor(null, 'Серия 035'));

        // Уточнённые правила — раньше общих
        $this->assertSame(['Смесители', 'Запчасти'], $this->map->ruleFor('Адаптер 220В для сенсорного смесителя Savol S-GY001', null)[0]);
        $this->assertSame(['Смесители', 'Запчасти'], $this->map->ruleFor('Гайка для смесителя Savol S04', null)[0]);
        $this->assertSame(['Сантехническая арматура (унитаз, сифоны)', 'Сифоны'], $this->map->ruleFor('Шайба сливной пробки КПП', null)[0]);
        $this->assertSame(['Аксессуары для ванной', 'Держатели и стойки'], $this->map->ruleFor('Труба для душевой стойки', null)[0]);
        $this->assertSame(['Аксессуары для ванной', 'Держатели и стойки'], $this->map->ruleFor('Держатель для бумажных полотенец', null)[0]);
        $this->assertSame(['Аксессуары для ванной', 'Держатели и стойки'], $this->map->ruleFor('Металлическая корзинка', null)[0]);
        $this->assertSame(['Аксессуары для ванной', 'Держатели и стойки'], $this->map->ruleFor('Поручень для ванной', null)[0]);
        $this->assertSame(['Гибкая подводка и шланги', 'Гибкая подводка для воды'], $this->map->ruleFor('Подгодка Г1/2-Г1/2 40 см', null)[0]);

        $this->assertSame(['Смесители'], $this->map->noveltySeriesPath('Cмесители (Новинки!)'));
        $this->assertSame('Без категории', $this->map->uncategorized());
    }

    public function test_attribute_keys_are_merged_and_skipped(): void
    {
        $this->assertSame('Цвет', $this->map->attributeFor('Цвет покрытия'));
        $this->assertSame('Рабочее давление', $this->map->attributeFor('Рабочее давление (Ру)'));
        $this->assertSame('Рабочее давление', $this->map->attributeFor('давление, бар'));
        $this->assertSame('Длина упаковки', $this->map->attributeFor('Длина индивидуальной упаковки, cм'));
        $this->assertNull($this->map->attributeFor('Ссылка на видео'));

        $this->assertTrue($this->map->isSkippedKey('Производитель'));
        $this->assertFalse($this->map->isSkippedKey('Цвет'));

        $this->assertTrue($this->map->attributeDef('Вес')['logistics']);
        $this->assertTrue($this->map->attributeDef('Код ТН ВЭД')['admin']);
        $this->assertSame(['type' => 'number', 'unit' => 'мм'], array_intersect_key($this->map->attributeDef('Размер картриджа'), ['type' => 1, 'unit' => 1]));
    }

    public function test_length_is_split_by_unit_and_video_url_is_a_field(): void
    {
        // «Длина» без уточнения: см и мм — разные характеристики, без пересчёта; метры — не загружаются
        $this->assertSame('Длина, см', $this->map->attributeByUnit('Длина', '150 см'));
        $this->assertSame('Длина, мм', $this->map->attributeByUnit('Длина', '172 мм'));
        $this->assertSame('Длина, мм', $this->map->attributeByUnit('Длина', '100-195 mm'));
        $this->assertFalse($this->map->attributeByUnit('Длина', '3,35 м'));
        $this->assertNull($this->map->attributeByUnit('Цвет', 'хром'));
        $this->assertSame('Длина, мм', $this->map->attributeFor('Длина,мм'));
        $this->assertSame('Длина, см', $this->map->attributeFor('Длина (см)'));

        $this->assertSame('Резьба', $this->map->attributeFor('Резьбы'));
        $this->assertSame('Наружный диаметр', $this->map->attributeFor('Наружный диаметр (мм)'));
        foreach (['Межосевое расстояние', 'Покрытие', 'Гидрозатвор', 'Переключатель'] as $key) {
            $this->assertSame($key, $this->map->attributeFor($key));
        }

        $this->assertSame('video_url', $this->map->fieldFor('Ссылка на видео'));
        $this->assertSame('https://vkvideo.ru/video_ext.php?oid=-1&id=2&hd=2',
            CatalogMapping::videoUrl('<iframe src="https://vkvideo.ru/video_ext.php?oid=-1&id=2&hd=2" width="853"></iframe>'));
        $this->assertSame('https://youtu.be/I2HlNPBcaBQ', CatalogMapping::videoUrl('https://youtu.be/I2HlNPBcaBQ'));
        $this->assertNull(CatalogMapping::videoUrl('<ifr ame width="853">'));
    }

    public function test_values_are_normalized(): void
    {
        $this->assertSame(['35', 35.0], $this->map->normalizeValue('Размер картриджа', '35 мм'));
        $this->assertSame(['2,6', 2.6], $this->map->normalizeValue('Вес', '2.6'));
        $this->assertSame(['5', 5.0], $this->map->normalizeValue('Гарантия', '5 лет'));
        // Число с пояснением — текстом, число для фильтра — первое
        $this->assertSame(['6 бар (10 бар для испытания оборудования)', 6.0], $this->map->normalizeValue('Рабочее давление', '6 бар (10 бар для испытания оборудования)'));
        $this->assertSame(['до 10 бар', null], $this->map->normalizeValue('Рабочее давление', 'до 10 бар'));
        // Синонимы и пробелы
        $this->assertSame(['черный', null], $this->map->normalizeValue('Цвет', '  Чёрный '));
        $this->assertSame(['круглая', null], $this->map->normalizeValue('Форма', 'округлая'));
        $this->assertSame(['на стену', null], $this->map->normalizeValue('Монтаж', 'настеная установка'));
    }

    public function test_cleanup_lists(): void
    {
        $this->assertSame(['LEDEME-L4655-3' => 'L4655-3', 'LEDEME-L4755-2' => 'L4755-2'], $this->map->duplicates());
        $this->assertContains('GR-100', $this->map->testProducts());
        // + строка заголовка каталога (sku «Артикул», название «Остатки»)
        $this->assertContains('Артикул', $this->map->testProducts());
        $this->assertCount(10, $this->map->testProducts());
    }
}
