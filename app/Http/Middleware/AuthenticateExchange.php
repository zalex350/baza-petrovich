<?php

namespace App\Http\Middleware;

use App\Exceptions\ExchangeApiException;
use App\Models\ExchangeIp;
use App\Models\ExchangeToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к API обмена с 1С (docs/1C_EXCHANGE_SPEC.md, раздел 2):
 *  1. IP из белого списка (админка «Обмен с 1С → IP»; пустой список — запрещено всё) — иначе 403.
 *     IP — $request->ip() с учётом TrustProxies (bootstrap/app.php): на стенде это адрес,
 *     который nginx сервера передал в X-Forwarded-For.
 *  2. Authorization: Bearer <действующий токен> — иначе 401.
 * Токен — в атрибуте запроса exchange_token; отметка последнего использования — не чаще раза в минуту.
 */
class AuthenticateExchange
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ExchangeIp::allows($request->ip())) {
            throw new ExchangeApiException(403, 'ip_forbidden', "Доступ с IP {$request->ip()} не разрешён");
        }

        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ExchangeToken::findActive($plain) : null;

        if (! $token) {
            throw new ExchangeApiException(401, 'unauthorized', 'Нет действующего токена: заголовок Authorization: Bearer <токен>',
                ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute()) || $token->last_used_ip !== $request->ip()) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('exchange_token', $token);

        return $next($request);
    }
}
