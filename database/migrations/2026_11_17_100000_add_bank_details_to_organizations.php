<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Банковские реквизиты организации клиента (необязательные): банк, BIC, IBAN (без пробелов),
 * адрес банка. Только добавляет колонки — данные не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('address');
            $table->string('bic', 11)->nullable()->after('bank_name');
            $table->string('iban', 34)->nullable()->after('bic');
            $table->string('bank_address')->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['bank_name', 'bic', 'iban', 'bank_address']));
    }
};
