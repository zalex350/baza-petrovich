<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Иконка категории (App\Services\CategoryIcons): icon — «material:имя», «custom:имя» или «upload»;
 * icon_svg — очищенная загруженная SVG (только при icon = upload). Только добавляет колонки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('image');
            $table->text('icon_svg')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['icon', 'icon_svg']);
        });
    }
};
