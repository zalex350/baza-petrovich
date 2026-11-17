<?php

namespace App\Support;

use App\Services\CatalogMenu;
use Illuminate\Support\Facades\DB;

/**
 * Названия категорий — с заглавной первой буквы, остальное как есть («смесители для биде» →
 * «Смесители для биде», «PEX-трубы» и «ГВС» не меняются). Используется импортом каталога
 * и для уже загруженных категорий (categories:capitalize, миграция).
 */
class CategoryName
{
    public static function capitalize(string $name): string
    {
        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    /**
     * Переименовать все категории со строчной первой буквы; адреса (slug) не меняются.
     * Идемпотентно: повторный вызов ничего не находит.
     *
     * @return list<array{id: int, from: string, to: string}>
     */
    public static function capitalizeAll(bool $dryRun = false): array
    {
        $changes = [];

        foreach (DB::table('categories')->orderBy('id')->get(['id', 'name']) as $category) {
            $name = self::capitalize($category->name);

            if ($name !== $category->name) {
                $changes[] = ['id' => $category->id, 'from' => $category->name, 'to' => $name];
            }
        }

        if (! $dryRun && $changes) {
            DB::transaction(function () use ($changes) {
                foreach ($changes as $change) {
                    DB::table('categories')->where('id', $change['id'])->update(['name' => $change['to'], 'updated_at' => now()]);
                }
            });

            CatalogMenu::flush();
        }

        return $changes;
    }
}
