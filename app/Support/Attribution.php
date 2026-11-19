<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Откуда пришёл покупатель: метки UTM, gclid (Google Ads), yclid (Яндекс Директ), внешний сайт-источник,
 * страница входа. Последний недиректный переход хранится в cookie на 30 дней (CaptureAttribution)
 * и сохраняется в заказ и заявку (attribution) — видно в админке, уходит в Битрикс24 (UTM_*).
 * Прямой заход (без меток и без внешнего referrer) прежний источник не затирает.
 */
class Attribution
{
    public const COOKIE = 'visit_source';

    public const DAYS = 30;

    public const PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'yclid', 'fbclid'];

    /** Новый источник из запроса или null (прямой заход, переход внутри сайта) */
    public static function fromRequest(Request $request): ?array
    {
        $params = collect(self::PARAMS)
            ->mapWithKeys(fn ($key) => [$key => self::clean($request->query($key))])
            ->filter()
            ->all();

        $referrer = (string) $request->headers->get('referer', '');
        $refHost = $referrer !== '' ? mb_strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        $external = $refHost !== '' && $refHost !== mb_strtolower($request->getHost());

        if (! $params && ! $external) {
            return null;
        }

        // Без меток — источник по сайту: поиск (organic) или переход по ссылке (referral)
        if (! $params) {
            $params = [
                'utm_source' => self::clean($refHost),
                'utm_medium' => preg_match('/(^|\.)(google|yandex|bing|mail|rambler|duckduckgo)\./', $refHost) ? 'organic' : 'referral',
            ];
        } elseif (! isset($params['utm_source']) && (isset($params['gclid']) || isset($params['gbraid']) || isset($params['wbraid']))) {
            $params += ['utm_source' => 'google', 'utm_medium' => 'cpc'];
        } elseif (! isset($params['utm_source']) && isset($params['yclid'])) {
            $params += ['utm_source' => 'yandex', 'utm_medium' => 'cpc'];
        }

        return $params + array_filter([
            'referrer' => $external ? Str::limit($referrer, 300, '') : null,
            'landing' => Str::limit('/'.ltrim($request->path(), '/'), 300, ''),
            'at' => now()->toAtomString(),
        ]);
    }

    /** Источник текущего посетителя (из cookie) */
    public static function current(?Request $request = null): ?array
    {
        $request ??= request();
        $fresh = $request->attributes->get(self::COOKIE);

        if (is_array($fresh)) {
            return $fresh;
        }

        $data = json_decode((string) $request->cookie(self::COOKIE), true);

        return is_array($data) && $data ? array_intersect_key($data, array_flip([...self::PARAMS, 'referrer', 'landing', 'at'])) : null;
    }

    /** Источник — в атрибуты запроса (заказ и заявка в этом же запросе) и cookie для ответа */
    public static function remember(Request $request, array $data): \Symfony\Component\HttpFoundation\Cookie
    {
        $request->attributes->set(self::COOKIE, $data);

        return Cookie::make(self::COOKIE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::DAYS * 24 * 60);
    }

    /** Кратко для админки и CRM: «google / cpc · кампания» */
    public static function label(?array $data): ?string
    {
        if (! $data) {
            return null;
        }

        $source = trim(($data['utm_source'] ?? '?').' / '.($data['utm_medium'] ?? '—'));

        return collect([$source, $data['utm_campaign'] ?? null, isset($data['gclid']) ? 'gclid' : null, isset($data['yclid']) ? 'yclid' : null])
            ->filter()->implode(' · ');
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));

        return $value !== '' ? Str::limit($value, 200, '') : null;
    }
}
