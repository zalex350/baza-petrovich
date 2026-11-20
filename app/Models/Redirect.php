<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Редирект со старого адреса (from_path — путь без домена и «/» по краям, нижний регистр)
 * на новый (to_url — путь /… или полный адрес). Проверяется, когда страница не найдена
 * (App\Support\LegacyUrls); hits — сколько раз сработал.
 */
class Redirect extends Model
{
    protected $fillable = ['from_path', 'to_url', 'status', 'note'];

    protected $casts = [
        'last_hit_at' => 'datetime',
        'hits' => 'integer',
        'status' => 'integer',
    ];

    /** Путь в едином виде: без домена, query и «/» по краям, декодированный, в нижнем регистре */
    public static function normalize(string $path): string
    {
        $path = (string) parse_url(trim($path), PHP_URL_PATH);

        return mb_strtolower(trim(rawurldecode($path), '/'));
    }

    /**
     * Запомнить смену адреса: старый → новый. Цепочки сокращаются (кто вёл на старый — ведёт на новый),
     * редирект с нового адреса удаляется (адрес снова занят живой страницей).
     */
    public static function remember(string $from, string $to, ?string $note = null): void
    {
        $fromPath = self::normalize($from);
        $toPath = self::normalize($to);

        if ($fromPath === '' || $fromPath === $toPath) {
            return;
        }

        static::query()->where('from_path', $toPath)->delete();
        static::query()->where('to_url', '/'.$fromPath)->update(['to_url' => '/'.$toPath]);
        static::query()->updateOrCreate(['from_path' => $fromPath], ['to_url' => '/'.$toPath, 'status' => 301, 'note' => $note]);
    }
}
