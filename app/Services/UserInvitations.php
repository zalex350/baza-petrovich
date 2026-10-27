<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Приглашения пользователей (брокер auth.passwords.invitations, 72 ч): выпуск ссылки
 * с письмом и показ ссылки администратору в карточке пользователя.
 *
 * Брокер хранит токен хешем — для показа рядом лежит сам токен, зашифрованный APP_KEY
 * (user_invitation_tokens.token_encrypted). Новая ссылка заменяет строку брокера — старая
 * перестаёт действовать; приглашение принято — брокер удаляет строку, блока нет.
 */
class UserInvitations
{
    /**
     * Выпустить ссылку и отправить письмо. Прежняя ссылка перестаёт действовать.
     */
    public function issue(User $user): void
    {
        // createToken, а не sendResetLink: без ограничения «раз в минуту» —
        // новую ссылку можно выпустить и сразу после приглашения
        $token = Password::broker(UserInvitation::BROKER)->createToken($user);

        $this->table()->where('email', $user->getEmailForPasswordReset())
            ->update(['token_encrypted' => Crypt::encryptString($token)]);

        $user->notify(new UserInvitation($token));
    }

    /**
     * Приглашение, которое ещё не принято: ссылка (null — токен не сохранён: выпущено до
     * показа ссылок или сменился APP_KEY), срок действия, истекло ли. Нет — null.
     *
     * @return array{url: ?string, expires_at: Carbon, expired: bool}|null
     */
    public function pending(User $user): ?array
    {
        $row = $this->table()->where('email', $user->getEmailForPasswordReset())->first();

        if (! $row) {
            return null;
        }

        $expiresAt = Carbon::parse($row->created_at)
            ->addMinutes((int) config('auth.passwords.'.UserInvitation::BROKER.'.expire'));

        try {
            $token = $row->token_encrypted ? Crypt::decryptString($row->token_encrypted) : null;
        } catch (DecryptException) {
            $token = null;
        }

        return [
            'url' => $token ? UserInvitation::urlFor($token, $user) : null,
            'expires_at' => $expiresAt,
            'expired' => $expiresAt->isPast(),
        ];
    }

    private function table(): Builder
    {
        return DB::table(config('auth.passwords.'.UserInvitation::BROKER.'.table'));
    }
}
