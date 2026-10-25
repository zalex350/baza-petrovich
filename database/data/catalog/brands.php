<?php

/*
|--------------------------------------------------------------------------
| Бренды каталога (docs/CATALOG_ANALYSIS.md, п. 3; «Решения», п. 4, 11)
|--------------------------------------------------------------------------
| canonical — как бренд пишется на сайте. Значение из catalog.db сравнивается без учёта регистра,
| пробелов и кавычек: «Ledeme», «LEDEME», «ledeme » → LEDEME.
| aliases — опечатки и бренды-серии: ключ (как в каталоге, без учёта регистра) → канонический бренд.
| series — бренд-серия → значение характеристики «Серия» (бренд при этом — канонический).
| Пустой бренд остаётся пустым («Решения», п. 3).
*/

return [

    'canonical' => [
        'TIM', 'LEDEME', 'SAVOL', 'WISENT', 'GERHANS', 'FMARK', 'LOFFREY', 'ORIO', 'JAVA', 'VALFEX',
        'ZEISSLER', 'UKLAD', 'TUBOFLEX', 'POLYTIM', 'VIRMUT', 'ITAP', 'FORNARA',
        'Sanplast', 'Zehnder',
        'Сантехкреп', 'Сантехмастер Групп', 'БелЦЕННЕР', 'АКВАБРАЙТ', 'НПО “Эрготех”',
    ],

    'aliases' => [
        'WIZENT' => 'WISENT',
        'ZEISSER' => 'ZEISSLER',
        'TIM Thermo' => 'TIM',
        'AquaTIM' => 'TIM',
    ],

    'series' => [
        'TIM Thermo' => 'Thermo',
        'AquaTIM' => 'AquaTIM',
    ],

];
