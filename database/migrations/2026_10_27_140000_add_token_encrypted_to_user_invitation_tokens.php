<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылка приглашения в карточке пользователя (админка): брокер хранит токен хешем, поэтому
 * рядом — сам токен, зашифрованный APP_KEY (App\Services\UserInvitations). Строку удаляет
 * брокер, когда приглашение принято или выпущено новое.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitation_tokens', function (Blueprint $table) {
            $table->text('token_encrypted')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitation_tokens', function (Blueprint $table) {
            $table->dropColumn('token_encrypted');
        });
    }
};
