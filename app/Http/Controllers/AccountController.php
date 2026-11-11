<?php

namespace App\Http\Controllers;

use App\Services\AssortmentExport;
use App\Services\ClientCodes;
use App\Services\UserInvitations;
use App\Support\LocalTime;

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
     * «Выгрузка ассортимента» (docs/MODULES.md §11): только у организации с кодами товаров,
     * иначе — 404 (раздела нет).
     */
    public function assortment(ClientCodes $codes)
    {
        $organization = $this->organizationWithCodesOrFail($codes);

        return view('account.assortment', [
            'organization' => $organization,
            'codesCount' => $organization->productCodes()->count(),
            'stockCap' => (int) config('shop.export_stock_cap'),
        ]);
    }

    public function assortmentDownload(ClientCodes $codes, AssortmentExport $export)
    {
        $organization = $this->organizationWithCodesOrFail($codes);

        $path = $export->write($organization, request()->boolean('without_code'));

        return response()->download($path, 'assortment-'.LocalTime::now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function organizationWithCodesOrFail(ClientCodes $codes)
    {
        $organization = $this->organizationOrFail();

        abort_unless($codes->has(), 404);

        return $organization;
    }

    /**
     * Организация текущего клиента; без организации — 404 (раздела нет).
     */
    private function organizationOrFail()
    {
        return auth()->user()->organization ?? abort(404);
    }
}
