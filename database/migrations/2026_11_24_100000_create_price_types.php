<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Типы цен (решение заказчика): справочник price_types, цены товара по всем типам — product_prices
 * (без НДС, как products.price), «Применяемая цена» организации — organizations.price_type_id
 * (null — по умолчанию).
 *
 * products.price остаётся — это цена типа по умолчанию (зеркало строки product_prices): витрина,
 * сортировки и отчёты для клиентов с ценой по умолчанию работают как раньше.
 * Данные: текущие products.price переносятся в тип opt «Оптовая» (по умолчанию).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->timestamps();

            $table->unique(['product_id', 'price_type_id']);
            $table->index(['price_type_id', 'price']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('price_type_id')->nullable()->after('email')->constrained()->nullOnDelete();
        });

        $now = now();
        DB::table('price_types')->insert([
            ['code' => 'opt', 'name' => 'Оптовая', 'is_hidden' => false, 'is_default' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'retail', 'name' => 'Розничная', 'is_hidden' => false, 'is_default' => false, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'special', 'name' => 'Специальная', 'is_hidden' => false, 'is_default' => false, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'vip', 'name' => 'VIP', 'is_hidden' => false, 'is_default' => false, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $opt = DB::table('price_types')->where('code', 'opt')->value('id');

        // Текущие цены — в тип opt (и удалённые мягко товары: могут вернуться)
        DB::table('products')->whereNotNull('price')->orderBy('id')->select(['id', 'price'])
            ->chunk(1000, function ($rows) use ($opt, $now) {
                DB::table('product_prices')->insert($rows->map(fn ($row) => [
                    'product_id' => $row->id, 'price_type_id' => $opt, 'price' => $row->price,
                    'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_type_id');
        });
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_types');
    }
};
