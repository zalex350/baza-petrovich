<?php

namespace App\Http\Controllers;

use App\Services\UserInvitations;

/**
 * Разделы личного кабинета (SRS §8) только для просмотра: реквизиты своей организации
 * (§8.5 — данные из 1С / админки) и её пользователи (UC-031 — создаёт и блокирует только
 * администратор, BR-115). Смена пароля (§8.13) — форма, сохраняет Auth\PasswordController.
 */
class AccountController extends Controller
{
    public function organization()
    {
        $organization = $this->organizationOrFail();

        return view('account.organization', [
            'organization' => $organization,
            'addressesCount' => $organization->deliveryAddresses()->count(),
        ]);
    }

    public function users(UserInvitations $invitations)
    {
        $organization = $this->organizationOrFail();

        $users = $organization->users()->orderBy('name')->get();

        // Статус: заблокирован / приглашён, пароль ещё не установлен / активен
        $statuses = $users->mapWithKeys(fn ($user) => [$user->id => match (true) {
            $user->isBlocked() => ['Заблокирован', 'neutral'],
            $invitations->pending($user) !== null => ['Приглашён, пароль не установлен', 'warning'],
            default => ['Активен', 'success'],
        }]);

        return view('account.users', compact('organization', 'users', 'statuses'));
    }

    public function password()
    {
        return view('account.password');
    }

    /**
     * Организация текущего клиента; без организации — 404 (раздела нет).
     */
    private function organizationOrFail()
    {
        return auth()->user()->organization ?? abort(404);
    }
}
