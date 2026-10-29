<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Информационные страницы (/page/{slug}): заголовок, текст (HTML из редактора), SEO, публикация.
 * Создаются три страницы меню с заглушками [ЗАПОЛНИТЬ] — тексты пишет заказчик в админке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        $now = now();

        DB::table('pages')->insert(collect([
            'delivery' => 'Доставка и оплата',
            'about' => 'О компании',
            'contacts' => 'Контакты',
        ])->map(fn ($title, $slug) => [
            'title' => $title,
            'slug' => $slug,
            'body' => '<p>[ЗАПОЛНИТЬ]</p>',
            'is_published' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
