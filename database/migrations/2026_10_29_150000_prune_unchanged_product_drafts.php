<?php

use App\Services\ProductDrafts;
use Illuminate\Database\Migrations\Migration;

/**
 * Очистка пустых черновиков: до исправления любое «Сохранить» создавало черновик, даже без
 * правок (переносы строк \r\n из браузера отличались от \n в базе). Удаляются черновики,
 * совпадающие с опубликованным товаром (ProductDrafts::differs). Идемпотентна: повторный
 * запуск ничего не меняет. Черновики с реальными правками и черновики удалённых товаров
 * не трогаются. Откат не нужен — удалённые черновики не содержали ничего, кроме
 * опубликованного.
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = app(ProductDrafts::class)->pruneUnchanged();

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "  Пустых черновиков удалено: {$deleted}".PHP_EOL;
        }
    }

    public function down(): void
    {
        // Удалённые черновики совпадали с опубликованными данными — восстанавливать нечего
    }
};
