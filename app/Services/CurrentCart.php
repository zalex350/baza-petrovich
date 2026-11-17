<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Корзина текущего посетителя.
 *
 *  - клиент (role client) — корзина пользователя (carts.user_id);
 *  - гость в рознице (shop.retail) — корзина по cookie cart_token (carts.token, user_id пуст),
 *    живёт 60 дней; создаётся при первом добавлении товара;
 *  - администратор и гость B2B — корзины нет.
 *
 * После входа клиента гостевая корзина переносится в его корзину (mergeGuestInto, слушатель Login):
 * количества одного товара складываются.
 */
class CurrentCart
{
    public const COOKIE = 'cart_token';

    /** Срок cookie, минут (60 дней) */
    public const LIFETIME = 60 * 24 * 60;

    /** Токен, выданный в этом запросе (cookie уйдёт с ответом) */
    private ?string $issued = null;

    public function user(): ?User
    {
        return auth()->user();
    }

    /** Может ли посетитель класть товары в корзину и оформлять заказ */
    public function canBuy(): bool
    {
        $user = $this->user();

        return $user ? $user->isClient() : (bool) config('shop.retail');
    }

    public function isGuest(): bool
    {
        return $this->user() === null && (bool) config('shop.retail');
    }

    /** Токен гостевой корзины из cookie (или выданный в этом запросе) */
    public function token(): ?string
    {
        $token = $this->issued ?? request()->cookie(self::COOKIE);

        return is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) ? $token : null;
    }

    public function get(): ?Cart
    {
        if (! $this->canBuy()) {
            return null;
        }

        $user = $this->user();

        if ($user) {
            return $user->cart()->first();
        }

        $token = $this->token();

        return $token ? Cart::where('token', $token)->whereNull('user_id')->first() : null;
    }

    public function getOrCreate(): Cart
    {
        $user = $this->user();

        if ($user) {
            return $user->cart()->firstOrCreate();
        }

        $token = $this->token();

        if ($token && ($cart = Cart::where('token', $token)->whereNull('user_id')->first())) {
            return $cart;
        }

        $this->issued = Str::random(40);
        Cookie::queue(self::COOKIE, $this->issued, self::LIFETIME);

        return Cart::create(['token' => $this->issued]);
    }

    /** Ограничить запрос (join carts) корзиной текущего посетителя; нет корзины — пусто */
    public function constrain(QueryBuilder|\Illuminate\Database\Eloquent\Builder $query, string $carts = 'carts'): void
    {
        $user = $this->user();
        $token = $this->token();

        match (true) {
            ! $this->canBuy() => $query->whereRaw('1 = 0'),
            $user !== null => $query->where("{$carts}.user_id", $user->id),
            $token !== null => $query->where("{$carts}.token", $token)->whereNull("{$carts}.user_id"),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Позиция принадлежит корзине текущего посетителя */
    public function owns(CartItem $item): bool
    {
        $cart = $item->cart;

        if (! $cart || ! $this->canBuy()) {
            return false;
        }

        $user = $this->user();

        return $user
            ? $cart->user_id === $user->id
            : $cart->user_id === null && $cart->token !== null && hash_equals($cart->token, (string) $this->token());
    }

    /**
     * Перенести гостевую корзину (по cookie) в корзину клиента после входа. Администратору — нет:
     * гостевая корзина остаётся до выхода.
     */
    public function mergeGuestInto(User $user): void
    {
        $token = request()->cookie(self::COOKIE);

        if (! $user->isClient() || ! is_string($token) || $token === '') {
            return;
        }

        $guest = Cart::where('token', $token)->whereNull('user_id')->first();

        if (! $guest) {
            return;
        }

        DB::transaction(function () use ($guest, $user) {
            $cart = $user->cart()->firstOrCreate();

            foreach ($guest->items()->get() as $item) {
                $existing = $cart->items()->where('product_id', $item->product_id)->first();

                if ($existing) {
                    $existing->increment('quantity', $item->quantity);
                } else {
                    $item->cart_id = $cart->id;
                    $item->save();
                }
            }

            $guest->items()->delete();
            $guest->delete();
        });

        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
