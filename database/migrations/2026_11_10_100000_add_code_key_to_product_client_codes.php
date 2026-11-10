<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * code_key — код клиента в нижнем регистре без пробелов по краям (ProductClientCode::key()).
 * Поиск по коду (поиск, подсказки, «Добавить по вашему коду») сравнивает code_key, а не
 * LOWER(code): SQLite в тестах не приводит кириллицу к нижнему регистру, MySQL — приводит;
 * с готовым ключом результат одинаков в обеих базах. Заполняется моделью и импортом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_client_codes', function (Blueprint $table) {
            $table->string('code_key', 100)->default('')->after('code');
            $table->index(['organization_id', 'code_key']);
        });

        DB::table('product_client_codes')->orderBy('id')->chunkById(500, function ($codes) {
            foreach ($codes as $code) {
                DB::table('product_client_codes')->where('id', $code->id)
                    ->update(['code_key' => mb_strtolower(trim($code->code))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_client_codes', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'code_key']);
            $table->dropColumn('code_key');
        });
    }
};
