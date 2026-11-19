<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Наши работы» (works + work_images) и «Отзывы» (reviews: текст и/или фото-скан, группа, оценка).
 * source_key — ключ импорта со старого сайта (petrovich:works).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('tag', 100)->nullable()->index();
            $table->date('completed_on')->nullable();
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('source_key')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('work_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('author')->nullable();
            $table->text('text')->nullable();
            $table->string('image')->nullable();
            $table->string('group', 100)->nullable()->index();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->foreignId('work_id')->nullable()->constrained()->nullOnDelete();
            $table->date('reviewed_on')->nullable();
            $table->boolean('is_published')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('source_key')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('work_images');
        Schema::dropIfExists('works');
    }
};
