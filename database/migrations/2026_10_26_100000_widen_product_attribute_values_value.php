<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Значение характеристики — до 1000 символов (в каталоге есть «Комплектация» и «Конструкция»
 * длиннее 255). Индекс (attribute_id, value) в MySQL — по префиксу value(191): полный ключ
 * не помещается в лимит длины индекса.
 */
return new class extends Migration
{
    private const INDEX = 'product_attribute_values_attribute_id_value_index';

    public function up(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->string('value', 1000)->change();
        });

        $this->addIndex();
    }

    public function down(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->string('value', 255)->change();
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->index(['attribute_id', 'value'], self::INDEX);
        });
    }

    private function addIndex(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE INDEX '.self::INDEX.' ON product_attribute_values (attribute_id, value(191))');

            return;
        }

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->index(['attribute_id', 'value'], self::INDEX);
        });
    }
};
