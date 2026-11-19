<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Quiz;
use Illuminate\Console\Command;

/**
 * Стартовые квизы «Петровича» (если квизов ещё нет): калькулятор забора — на главной и в разделах заборов,
 * профнастила, металлоштакетника, сетки; калькулятор ворот — в разделах ворот и автоматики. Дальше — в админке «Квизы».
 */
class PetrovichQuizzes extends Command
{
    protected $signature = 'petrovich:quizzes {--force : создать, даже если квизы уже есть}';

    protected $description = 'Стартовые квизы: калькуляторы забора и ворот';

    public function handle(): int
    {
        if (Quiz::exists() && ! $this->option('force')) {
            $this->info('Квизы уже есть — ничего не создано');

            return self::SUCCESS;
        }

        $cat = fn (array $keys) => Category::whereIn('source_key', $keys)->pluck('id')->map(fn ($id) => 'category:'.$id)->all();

        Quiz::create([
            'title' => 'Рассчитайте стоимость забора за 1 минуту',
            'intro' => 'Ответьте на 5 вопросов — пришлём расчёт материалов и монтажа «под ключ».',
            'button_label' => 'Рассчитать забор',
            'display' => 'both',
            'steps' => Quiz::normalizeSteps([
                ['question' => 'Из чего будет забор?', 'type' => 'single', 'options' => ['Профнастил', 'Металлоштакетник', 'Сетка-рабица', '3D-панели', 'Жалюзи', 'Пока не знаю'], 'required' => true],
                ['question' => 'Какая длина забора?', 'type' => 'number', 'hint' => 'В метрах, можно примерно', 'required' => true],
                ['question' => 'Какая высота?', 'type' => 'single', 'options' => ['1,2 м', '1,5 м', '1,8 м', '2 м', 'Другая'], 'required' => true],
                ['question' => 'Что ещё нужно?', 'type' => 'multi', 'options' => ['Калитка', 'Распашные ворота', 'Откатные ворота', 'Автоматика для ворот'], 'required' => false],
                ['question' => 'Монтаж нужен?', 'type' => 'single', 'options' => ['Да, «под ключ»', 'Нет, только материалы', 'Нужна консультация'], 'required' => true],
            ]),
            'result_title' => 'Спасибо! Расчёт уже готовится',
            'result_text' => 'Менеджер перезвонит в рабочее время и пришлёт расчёт материалов и монтажа.',
            'targets' => ['home', ...$cat(['zabory', 'profnastil', 'metalloshtaketnik', 'setka', 'zhalyuzi'])],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Quiz::create([
            'title' => 'Подберём ворота и автоматику',
            'intro' => '4 вопроса — и менеджер предложит варианты под ваш въезд.',
            'button_label' => 'Подобрать ворота',
            'display' => 'inline',
            'steps' => Quiz::normalizeSteps([
                ['question' => 'Какие ворота нужны?', 'type' => 'single', 'options' => ['Откатные', 'Распашные', 'Пока не знаю'], 'required' => true],
                ['question' => 'Ширина проёма?', 'type' => 'single', 'options' => ['До 3 м', '3–4 м', '4–5 м', 'Больше 5 м'], 'required' => true],
                ['question' => 'Нужна автоматика?', 'type' => 'single', 'options' => ['Да', 'Нет', 'Возможно позже'], 'required' => true],
                ['question' => 'Монтаж нужен?', 'type' => 'single', 'options' => ['Да, «под ключ»', 'Нет, только комплект'], 'required' => true],
            ]),
            'result_title' => 'Спасибо!',
            'result_text' => 'Менеджер перезвонит и предложит варианты ворот и автоматики.',
            'targets' => $cat(['proizvodstvo', 'komplektuyushhie-dlya-vorot']),
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->info('Создано квизов: 2');

        return self::SUCCESS;
    }
}
