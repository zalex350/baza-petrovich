<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     * Ссылка приглашения проверяется сразу: использованная (пароль уже установлен), устаревшая
     * или подменённая — страница «Ссылка недействительна или устарела», без формы.
     */
    public function create(Request $request): Response|View
    {
        if ($request->boolean('invite') && ! $this->invitationValid($request)) {
            return response()->view('auth.invite-invalid', [], 410);
        }

        return view('auth.reset-password', ['request' => $request]);
    }

    private function invitationValid(Request $request): bool
    {
        $user = User::where('email', (string) $request->query('email'))->first();

        return $user !== null
            && Password::broker(UserInvitation::BROKER)->tokenExists($user, (string) $request->route('token'));
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        // Приглашение и обычный сброс — разные брокеры: разный срок и таблица токенов
        $invite = $request->boolean('invite');
        $broker = $invite ? UserInvitation::BROKER : null;
        $invited = null;

        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, &$invited) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $invited = $user;
            }
        );

        // Приглашение принято — сразу вход: клиент — в каталог, администратор — к заказам
        if ($invite && $status == Password::PASSWORD_RESET && $invited) {
            Auth::login($invited);
            $request->session()->regenerate();

            return redirect($invited->isAdmin() ? '/admin/orders' : route('catalog.index'))
                ->with('success', 'Пароль установлен. Добро пожаловать!');
        }

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email', 'invite'))
                        ->withErrors(['email' => __($status)]);
    }
}
