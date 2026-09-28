<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UserInvitation;
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

    public function index()
    {
        $search = trim((string) request('q', ''));
        $role   = UserRole::tryFrom((string) request('role'));
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
            'users'  => $users,
            'search' => $search,
            'role'   => $role,
            'status' => $status,
        ]);
    }

    public function create()
    {
        return view('admin.user_form', [
            'user'          => new User(),
            'organizations' => $this->organizations(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $data = request()->validate(
            $this->rules() + [
                'password_mode' => ['required', Rule::in([self::PASSWORD_MANUAL, self::PASSWORD_INVITE])],
                'password'      => [
                    Rule::requiredIf(request('password_mode') === self::PASSWORD_MANUAL),
                    'nullable',
                    PasswordRule::defaults(),
                ],
            ],
            $this->messages()
        );

        $invite = $data['password_mode'] === self::PASSWORD_INVITE;

        $user = new User([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'phone'           => $data['phone'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
            // При приглашении — случайный пароль, пользователь задаст свой по ссылке
            'password'        => $invite ? Str::password(32) : $data['password'],
        ]);
        $user->role              = UserRole::from($data['role']);
        $user->email_verified_at = now();
        $user->save();

        if (! $invite) {
            return redirect("/admin/user/{$user->id}")->with('success', 'Пользователь создан');
        }

        $status = Password::sendResetLink(
            ['email' => $user->email],
            fn (User $user, string $token) => $user->notify(new UserInvitation($token))
        );

        return $status === Password::RESET_LINK_SENT
            ? redirect("/admin/user/{$user->id}")
                ->with('success', "Пользователь создан, ссылка для установки пароля отправлена на {$user->email}")
            : redirect("/admin/user/{$user->id}")
                ->with('error', 'Пользователь создан, но ссылку отправить не удалось: '.__($status));
    }

    public function show($id)
    {
        $user = User::with('organization')->withCount('orders')->findOrFail($id);

        return view('admin.user', compact('user'));
    }

    public function edit($id)
    {
        return view('admin.user_form', [
            'user'          => User::findOrFail($id),
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
        $data = request()->validate($this->rules($user), $this->messages());

        if ($this->isSelf($user) && $data['role'] !== UserRole::Admin->value) {
            return back()->withInput()->with('error', 'Нельзя снять с себя роль администратора');
        }

        $user->fill([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'phone'           => $data['phone'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
        ]);
        $user->role = UserRole::from($data['role']);
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

    private function rules(?User $user = null): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user?->id)],
            'phone'           => ['nullable', 'string', 'max:50'],
            'role'            => ['required', Rule::enum(UserRole::class)],
            'organization_id' => User::organizationRules((string) request('role')),
        ];
    }

    private function messages(): array
    {
        return [
            'organization_id.required' => 'Для клиента необходимо выбрать организацию.',
            'organization_id.exists'   => 'Выбранная организация не найдена.',
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
