<?php

use App\Services\Search\SearchIndex;
use App\Services\Search\SearchSynonyms;
use Database\Seeders\SearchSynonymSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поиск по формам слов и синонимам (App\Services\Search):
 * - products.search_text — слова и основы названий, бренда, категории; заполняется здесь же
 *   для всех товаров (производные данные, исходные поля не меняются; повторить — search:reindex);
 * - search_synonyms — синонимы из админки, стартовый набор — SearchSynonymSeeder (только в пустую таблицу).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_text')->nullable()->after('display_name');
        });

        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('terms', 500);
            $table->string('targets', 500)->nullable();
            $table->timestamps();
        });

        (new SearchSynonymSeeder)->run();
        SearchSynonyms::flush();

        app(SearchIndex::class)->reindex();
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
        SearchSynonyms::flush();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
