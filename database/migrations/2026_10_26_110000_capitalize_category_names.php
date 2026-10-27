<?php

use App\Support\CategoryName;
use Illuminate\Database\Migrations\Migration;

/**
 * Названия категорий — с заглавной первой буквы (то же — команда categories:capitalize).
 * Адреса (slug) не меняются. Отката нет: прежнее написание не хранится (бэкап перед миграцией).
 */
return new class extends Migration
{
    public function up(): void
    {
        CategoryName::capitalizeAll();
    }

    public function down(): void
    {
        //
    }
};
