<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\UserInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Администрирование пользователей (SRS §10.5, BR-115).
 * Удаления нет: вместо него блокировка, история заказов сохраняется.
 */
class AdminUserController extends Controller
{
    public const PASSWORD_MANUAL = 'manual';

    public const PASSWORD_INVITE = 'invite';

    public function __construct(private readonly UserInvitations $invitations) {}

    public function index()
    {
        $search = trim((string) request('q', ''));
        $role = UserRole::tryFrom((string) request('role'));
        $status = in_array(request('status'), ['active', 'blocked'], true) ? request('status') : null;

        $users = User::with('organization')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', $like));
                });
            })
            ->when($role, fn ($query) => $query->where('role', $role->value))
            ->when($status === 'active', fn ($query) => $query->whereNull('blocked_at'))
            ->when($status === 'blocked', fn ($query) => $query->whereNotNull('blocked_at'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.users', [
            'users' => $users,
            'search' => $search,
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function create()
    {
        return view('admin.user_form', [
            'user' => new User,
            'organizations' => $this->organizations(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $this->normalizeEmail();

        $data = request()->validate(
            $this->rules() + [
                'password_mode' => ['required', Rule::in([self::PASSWORD_MANUAL, self::PASSWORD_INVITE])],
                'password' => [
                    Rule::requiredIf(request('password_mode') === self::PASSWORD_MANUAL),
                    'nullable',
                    PasswordRule::defaults(),
                ],
            ],
            $this->messages()
        );

        $invite = $data['password_mode'] === self::PASSWORD_INVITE;

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            // При приглашении — случайный пароль, пользователь задаст свой по ссылке
            'password' => $invite ? Str::password(32) : $data['password'],
        ]);
        $user->role = UserRole::from($data['role']);
        $user->email_verified_at = now();
        $this->fillNotifications($user, $data);
        $user->save();

        if (! $invite) {
            return redirect("/admin/user/{$user->id}")->with('success', 'Пользователь создан');
        }

        $this->invitations->issue($user);

        return redirect("/admin/user/{$user->id}")
            ->with('success', "Пользователь создан, ссылка для установки пароля отправлена на {$user->email}");
    }

    public function show($id)
    {
        $user = User::with('organization')->withCount('orders')->findOrFail($id);

        return view('admin.user', [
            'user' => $user,
            'invitation' => $this->invitations->pending($user),
        ]);
    }

    /**
     * «Выпустить новую ссылку»: прежняя перестаёт действовать, новая уходит письмом
     * и видна в карточке. Только пока приглашение не принято.
     */
    public function reissueInvitation($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! $this->invitations->pending($user)) {
            return back()->with('error', 'Приглашения нет: пароль уже установлен');
        }

        $this->invitations->issue($user);

        return back()->with('success', "Выпущена новая ссылка приглашения и отправлена на {$user->email}. Прежняя больше не действует");
    }

    public function edit($id)
    {
        return view('admin.user_form', [
            'user' => User::findOrFail($id),
            'organizations' => $this->organizations(),
        ]);
    }

    /**
     * Меняются только данные пользователя; заказы (в т.ч. их organization_id)
     * остаются как были оформлены.
     */
    public function update($id): RedirectResponse
    {
        $user = User::findOrFail($id);
        $this->normalizeEmail();

        $data = request()->validate($this->rules($user), $this->messages());

        if ($this->isSelf($user) && $data['role'] !== UserRole::Admin->value) {
            return back()->withInput()->with('error', 'Нельзя снять с себя роль администратора');
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
        ]);
        $user->role = UserRole::from($data['role']);
        $this->fillNotifications($user, $data);
        $user->save();

        return redirect("/admin/user/{$user->id}")->with('success', 'Пользователь сохранён');
    }

    public function block($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if ($this->isSelf($user)) {
            return back()->with('error', 'Нельзя заблокировать самого себя');
        }

        $user->forceFill(['blocked_at' => now()])->save();

        return back()->with('success', "Пользователь {$user->email} заблокирован");
    }

    public function unblock($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        $user->forceFill(['blocked_at' => null])->save();

        return back()->with('success', "Пользователь {$user->email} разблокирован");
    }

    public function resetPassword($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        $status = Password::sendResetLink(['email' => $user->email]);

        return $status === Password::RESET_LINK_SENT
            ? back()->with('success', "Ссылка для восстановления пароля отправлена на {$user->email}")
            : back()->with('error', 'Не удалось отправить ссылку: '.__($status));
    }

    /**
     * Блок «Уведомления на почту» в карточке администратора (у клиентов настроек нет).
     */
    public function updateNotifications($id): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (! $user->isAdmin()) {
            return back()->with('error', 'Уведомления на почту настраиваются только администраторам');
        }

        $data = request()->validate([
            'notify_new_order' => ['required', 'boolean'],
            'notify_cancel_request' => ['required', 'boolean'],
            'notify_partner_request' => ['required', 'boolean'],
            'notify_exchange' => ['required', 'boolean'],
        ]);

        $this->fillNotifications($user, $data);
        $user->save();

        return back()->with('success', 'Настройки уведомлений сохранены');
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'organization_id' => User::organizationRules((string) request('role')),
            'notify_new_order' => ['sometimes', 'boolean'],
            'notify_cancel_request' => ['sometimes', 'boolean'],
            'notify_partner_request' => ['sometimes', 'boolean'],
            'notify_exchange' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Email-уведомления значимы только для администратора: у клиента значения не меняются.
     * Поле не пришло (форма без блока) — настройка остаётся прежней.
     */
    private function fillNotifications(User $user, array $data): void
    {
        if (! $user->isAdmin()) {
            return;
        }

        foreach (['notify_new_order', 'notify_cancel_request', 'notify_partner_request', 'notify_exchange'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = (bool) $data[$field];
            }
        }
    }

    /**
     * Email без пробелов по краям и строчными: «Ivanov@Santeh.by » — тот же адрес, не ошибка формы.
     */
    private function normalizeEmail(): void
    {
        if (is_string(request('email'))) {
            request()->merge(['email' => mb_strtolower(trim(request('email')))]);
        }
    }

    private function messages(): array
    {
        return [
            'name.required' => 'Укажите ФИО.',
            'email.required' => 'Укажите email.',
            'email.email' => 'Неверный формат email.',
            'email.lowercase' => 'Email — строчными буквами.',
            'email.unique' => 'Пользователь с таким email уже есть.',
            'phone.max' => 'Телефон — не длиннее 50 символов.',
            'role.required' => 'Выберите роль.',
            'password_mode.required' => 'Выберите, как задать пароль.',
            'password.required' => 'Укажите пароль или выберите отправку ссылки.',
            'organization_id.required' => 'Для клиента необходимо выбрать организацию.',
            'organization_id.exists' => 'Выбранная организация не найдена.',
        ];
    }

    private function organizations()
    {
        return Organization::orderBy('name')->get(['id', 'name', 'unp']);
    }

    private function isSelf(User $user): bool
    {
        return $user->is(auth()->user());
    }
}
