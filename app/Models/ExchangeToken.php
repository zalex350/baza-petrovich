<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bearer-токен API обмена с 1С. Хранится только SHA-256; сам токен показывается один раз
 * при создании (админка «Обмен с 1С → Токены»). Отозванный (revoked_at) не принимается.
 */
class ExchangeToken extends Model
{
    public const PREFIX = 'sm1c_';

    protected $fillable = ['name', 'created_by'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Новый токен: [модель, открытый токен]. Открытый токен больше нигде не сохраняется.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?User $by): array
    {
        $plain = self::PREFIX.Str::random(48);

        $token = new self(['name' => $name, 'created_by' => $by?->id]);
        $token->token_hash = self::hash($plain);
        $token->prefix = substr($plain, 0, strlen(self::PREFIX) + 6);
        $token->save();

        return [$token, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Действующий токен по открытому значению или null */
    public static function findActive(string $plain): ?self
    {
        return self::query()->where('token_hash', self::hash($plain))->whereNull('revoked_at')->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
