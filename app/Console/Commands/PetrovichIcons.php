<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\CatalogMenu;
use Illuminate\Console\Command;

/**
 * Иконки разделов «Петровича» (набор resources/icons/metal) по правилам
 * database/data/petrovich/category_icons.php: только разделам без иконки; --force — всем.
 * Нет совпадения у подраздела — иконка ближайшего родителя.
 */
class PetrovichIcons extends Command
{
    protected $signature = 'petrovich:icons {--force : заменить и уже заданные иконки}';

    protected $description = 'Иконки разделов каталога металлобазы';

    public function handle(): int
    {
        $count = self::apply((bool) $this->option('force'));
        $this->info("Иконок назначено: {$count}");

        return self::SUCCESS;
    }

    public static function iconFor(string $name): ?string
    {
        $name = mb_strtolower($name);

        foreach (require database_path('data/petrovich/category_icons.php') as $pattern => $icon) {
            if (preg_match($pattern, $name)) {
                return 'metal:'.$icon;
            }
        }

        return null;
    }

    public static function apply(bool $force = false): int
    {
        $categories = Category::query()->get(['id', 'name', 'parent_id', 'icon'])->keyBy('id');
        $resolved = [];

        $resolve = function (int $id) use (&$resolve, &$resolved, $categories): ?string {
            if (array_key_exists($id, $resolved)) {
                return $resolved[$id];
            }

            $category = $categories->get($id);
            $resolved[$id] = null; // защита от цикла

            return $resolved[$id] = self::iconFor($category->name)
                ?? ($category->parent_id && $categories->has($category->parent_id) ? $resolve($category->parent_id) : null);
        };

        $count = 0;

        foreach ($categories as $category) {
            if (! $force && filled($category->icon)) {
                continue;
            }

            $icon = $resolve($category->id);

            if ($icon && $icon !== $category->icon) {
                Category::whereKey($category->id)->update(['icon' => $icon]);
                $count++;
            }
        }

        app(CatalogMenu::class)->flush();

        return $count;
    }
}
