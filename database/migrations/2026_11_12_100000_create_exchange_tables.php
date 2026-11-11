<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API обмена с 1С (docs/1C_EXCHANGE_SPEC.md, разделы 2 и 8).
 * exchange_tokens — Bearer-токены (хранится только SHA-256), exchange_ips — белый список,
 * exchange_jobs — задания (принятые файлы и выдачи), exchange_job_errors — ошибки по строкам.
 * users.notify_exchange — письма администраторам о сбоях обмена.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            // Начало токена — узнать его в списке, не храня целиком
            $table->string('prefix', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_ips', function (Blueprint $table) {
            $table->id();
            // Адрес или подсеть CIDR (IPv4 / IPv6)
            $table->string('ip', 64)->unique();
            $table->string('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('exchange_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30)->index();
            $table->string('direction', 10);
            $table->string('mode', 10)->nullable();
            $table->string('status', 20)->index();
            $table->string('exchange_id', 64)->nullable()->unique();
            $table->foreignId('token_id')->nullable()->constrained('exchange_tokens')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            // Путь в архиве (диск exchange: ГГГГ/ММ/<id>.csv); null — файла нет или удалён по сроку
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('content_type', 50)->nullable();
            $table->boolean('gzip')->default(false);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            // Причина failed
            $table->text('message')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('file_pruned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exchange_job_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('exchange_job_id')->constrained('exchange_jobs')->cascadeOnDelete();
            $table->unsignedInteger('line')->nullable();
            $table->string('guid', 64)->nullable();
            $table->string('sku')->nullable();
            $table->text('message');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_exchange')->default(true)->after('notify_partner_request');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_exchange');
        });

        Schema::dropIfExists('exchange_job_errors');
        Schema::dropIfExists('exchange_jobs');
        Schema::dropIfExists('exchange_ips');
        Schema::dropIfExists('exchange_tokens');
    }
};
