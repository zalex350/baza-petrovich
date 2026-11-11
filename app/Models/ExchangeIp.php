<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Белый список IP API обмена с 1С: адрес или подсеть CIDR. Пустой список — запрещено всё.
 */
class ExchangeIp extends Model
{
    protected $fillable = ['ip', 'comment', 'created_by'];

    public static function allows(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $list = self::query()->pluck('ip')->all();

        return $list !== [] && IpUtils::checkIp($ip, $list);
    }

    /** Адрес или подсеть CIDR (IPv4 / IPv6) */
    public static function isValid(string $value): bool
    {
        [$address, $mask] = array_pad(explode('/', $value, 2), 2, null);

        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($mask) && (int) $mask >= 0 && (int) $mask <= $max;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
