<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Квизы (свой конструктор: шаги-вопросы, контакты в конце → заявка) и коды сторонних сервисов
 * (MoClients, Marquiz, JivoSite, Метрика…). targets — где показывать (App\Services\PageTargets).
 * leads.payload — ответы квиза.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->string('button_label', 100)->nullable();
            $table->string('display', 20)->default('inline');
            $table->json('steps');
            $table->string('result_title')->nullable();
            $table->text('result_text')->nullable();
            $table->json('targets');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('site_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('code');
            $table->string('position', 20)->default('body');
            $table->json('targets');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('comment');
            $table->foreignId('quiz_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quiz_id');
            $table->dropColumn('payload');
        });

        Schema::dropIfExists('site_scripts');
        Schema::dropIfExists('quizzes');
    }
};
