<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Массовая загрузка фото товаров (App\Services\Images\BulkImport): журнал загрузок.
 * Черновик (draft: файл загружен, есть план предпросмотра) → queued → running → done / failed;
 * непримененный черновик старше суток — expired (файл удаляется).
 * plan — сопоставление файлов товарам (JSON, может быть большим), report — не найдено,
 * неоднозначно, ошибки; счётчики — прогресс и итог.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10);          // zip | dir | csv
            $table->string('name');                // исходное имя файла или папка
            $table->string('path', 500)->nullable(); // черновик (диск local) или папка на сервере
            $table->string('mode', 10)->nullable(); // append | replace
            $table->string('status', 10)->default('draft');
            $table->longText('plan')->nullable();
            $table->json('report')->nullable();
            $table->unsignedInteger('files_total')->default(0);
            $table->unsignedInteger('products_total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->unsignedBigInteger('image_id_floor')->nullable(); // фото с id не больше — были до загрузки
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_imports');
    }
};
