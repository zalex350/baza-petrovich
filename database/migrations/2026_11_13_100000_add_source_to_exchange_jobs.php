<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Источник задания обмена: api — прислала 1С, manual — загрузил администратор в админке
 * (user_id — автор). Существующие задания — api.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_jobs', function (Blueprint $table) {
            $table->string('source', 10)->default('api')->after('direction');
            $table->foreignId('user_id')->nullable()->after('token_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('source');
        });
    }
};
