{{--
    Позиция корзины (макеты Main — строка таблицы, CartMobile — карточка).
    <x-cart-row :item="$item" :validation="$validation" />                  — строка (xl и шире)
    <x-cart-row :item="$item" :validation="$validation" layout="card" />    — карточка (уже xl)
    validation — проверка всех позиций (CartValidator): причины, нехватка остатка, изменение цены.
    Действия без перезагрузки — cart-page.js: галочка «в заказ» (POST /order/select/{id}),
    количество (x-qty, POST /order/update/{id}), удаление (x-confirm, POST /order/delete/{id}).
    Части с data-refresh обновляются после каждого действия; ключи у строки и карточки разные.
--}}
@props(['item', 'validation', 'layout' => 'row'])

@php
    $card = $layout === 'card';
    $key = ($card ? 'card-' : 'row-').$item->id;
    $product = $item->product;
    $unavailable = $validation->isUnavailable($item);
    $problems = $validation->for($item);
    $shortage = $validation->shortage($item);
    $priceChange = $validation->priceChange($item);
    $line = $item->vatLine();
    // «Ваш код» — код организации клиента (коды позиций загружает CartController::index)
    $clientCode = client_code($product);

    // Правило количества (OrderQuantity); количество не по правилу (правило сменилось) — поле
    // без кратности, чтобы клиент мог его исправить, ошибку показывает валидатор
    $qtyRule = \App\Support\OrderQuantity::for($product);
    $aligned = $qtyRule->allows($item->quantity);
    $min = $qtyRule->min;
    $step = $qtyRule->step;

    // Упаковка/кратность: только если отличается от «по одной»
    $pack = $product ? $qtyRule->label() : null;

    // Причины, кроме нехватки остатка (она — под количеством) и изменения цены (оно — у цены)
    $notes = collect($problems)->reject(fn ($p) => $p->type->isShortage() || $p->type === \App\Enums\CartProblemType::PriceChanged);

    $highlight = $shortage && ! $unavailable;
@endphp

<article
    {{ $attributes->class([
        'relative',
        $card ? 'flex flex-col gap-3 rounded-card border border-line p-3.5' : 'grid grid-cols-[1.5rem_minmax(0,1fr)_6rem_8rem_6rem_7rem_2.5rem] items-start gap-x-3 px-4 py-4',
        $card ? ($highlight ? 'bg-warn-row' : 'bg-surface') : ($highlight ? 'bg-warn-row' : ''),
        'opacity-70' => $unavailable,
    ]) }}
    data-cart-item="{{ $item->id }}"
>
    @if($card)
        <div class="flex items-start gap-3">
            {{-- Галочка «в заказ» --}}
            <span class="mt-0.5" data-refresh="{{ $key }}-check">
            <input
                type="checkbox"
                class="h-5 w-5 rounded border-line-strong text-primary focus:ring-primary disabled:opacity-40"
                aria-label="Включить в заказ"
                data-cart-select="{{ $item->id }}"
                @checked($item->selected)
                @disabled($unavailable)
            >
            </span>

            @include('partials.product_image', [
                'image' => $product?->mainImage,
                'alt'   => $item->title(),
                'class' => 'h-14 w-14 shrink-0 rounded-control border border-line bg-media object-contain p-1',
            ])

            <div class="min-w-0 flex-1">
                @if($product && ! $unavailable)
                    <a href="{{ $product->url() }}" class="font-semibold leading-snug text-ink hover:text-link">{{ $item->title() }}</a>
                @else
                    <span class="font-semibold leading-snug text-ink-muted">{{ $item->title() }}</span>
                @endif
                <div class="mt-0.5 text-meta text-ink-muted">
                    @if($product)Арт. {{ $product->displayArticle() }}@endif @if($product && ! $unavailable) · <x-price :amount="$item->price_at_add" vat="net" />/шт.@endif
                </div>
                @if($clientCode)
                    <div class="text-meta text-ink" data-client-code>Ваш код: <span class="font-semibold">{{ $clientCode }}</span></div>
                @endif
                <div class="mt-0.5 text-meta" data-refresh="{{ $key }}-stock">
                    @if($unavailable)
                        <span class="text-tone-danger-text">{{ $problems[0]->title() }}</span>
                    @elseif($shortage)
                        <span class="text-tone-danger-text">Доступно {{ $shortage->available }} шт.</span>
                    @else
                        <x-stock :product="$product" />
                    @endif
                </div>
            </div>

            <x-confirm
                action="/order/delete/{{ $item->id }}"
                label="Удалить"
                title="Удалить товар из корзины?"
                confirm-label="Удалить"
                icon="trash"
                class="-mr-1 -mt-1 shrink-0"
            >«{{ $item->title() }}» будет удалён из корзины.</x-confirm>
        </div>
    @else
        {{-- Галочка «в заказ» --}}
        <span class="pt-5" data-refresh="{{ $key }}-check">
            <input
                type="checkbox"
                class="h-5 w-5 rounded border-line-strong text-primary focus:ring-primary disabled:opacity-40"
                aria-label="Включить в заказ"
                data-cart-select="{{ $item->id }}"
                @checked($item->selected)
                @disabled($unavailable)
            >
        </span>

        <div class="flex min-w-0 gap-3.5">
            @include('partials.product_image', [
                'image' => $product?->mainImage,
                'alt'   => $item->title(),
                'class' => 'h-14 w-14 shrink-0 rounded-control border border-line bg-media object-contain p-1',
            ])

            <div class="min-w-0">
                @if($product && ! $unavailable)
                    <a href="{{ $product->url() }}" class="font-semibold text-ink hover:text-link">{{ $item->title() }}</a>
                @else
                    <span class="font-semibold text-ink-muted">{{ $item->title() }}</span>
                @endif
                <div class="mt-0.5 text-meta text-ink-muted">@if($product)Арт. {{ $product->displayArticle() }}@endif @if($product?->brand) · {{ $product->brand }}@endif</div>
                @if($clientCode)
                    <div class="text-meta text-ink" data-client-code>Ваш код: <span class="font-semibold">{{ $clientCode }}</span></div>
                @endif
                @if($pack)
                    <div class="mt-0.5 text-meta text-ink-muted">{{ $pack }}</div>
                @endif
            </div>
        </div>
    @endif

    @if($card && $pack)
        <div class="-mt-1 pl-8 text-meta text-ink-muted">{{ $pack }}</div>
    @endif

    {{-- Причины (недоступен, кратность) --}}
    <div @class(['text-meta text-tone-danger-text empty:hidden', 'col-start-2 col-end-8 -mt-2 order-last' => ! $card]) data-refresh="{{ $key }}-notes">@foreach($notes as $note)<div>{{ $note->type->isQuantityRule() ? $note->text() : $note->title() }}</div>@endforeach</div>

    @unless($card)
        {{-- Цена (розница — с НДС, B2B — без НДС); изменилась — было/стало (принять — в панели) --}}
        <div class="whitespace-nowrap pt-5 text-right tabular-nums" data-refresh="{{ $key }}-price">
            @if($unavailable)
                <span class="text-ink-muted">—</span>
            @elseif($priceChange)
                <div class="text-ink-muted line-through"><x-price :amount="$priceChange->oldPrice" /></div>
                <div class="font-semibold text-tone-warning-text"><x-price :amount="$priceChange->newPrice" /></div>
                <div class="text-meta text-tone-warning-text">{{ $priceChange->title() }}</div>
                <div class="text-meta text-ink-muted">{{ \Illuminate\Support\Str::ucfirst($priceChange->detail) }}</div>
            @else
                <x-price :amount="$item->price_at_add" />
            @endif
        </div>
    @endunless

    @if($card)
        <div class="flex items-center justify-between gap-3">
    @endif

    {{-- Количество --}}
    <div @class(['flex flex-col gap-1', 'items-center pt-1.5' => ! $card])>
        @if($unavailable)
            <span class="px-2 text-ink-muted tabular-nums">{{ $item->quantity }} шт.</span>
        @else
            <form method="POST" action="/order/update/{{ $item->id }}" data-cart-qty>
                @csrf
                <x-qty
                    name="quantity"
                    :id="'q-'.$key"
                    :value="$item->quantity"
                    :min="$aligned ? $min : 1"
                    :step="$aligned ? $step : 1"
                    :max="qty_max($product)"
                    :size="$card ? 'md' : 'sm'"
                    label="Количество"
                />
                <button type="submit" class="sr-only">Обновить количество</button>
            </form>
        @endif
        <div class="text-meta text-tone-danger-text empty:hidden" role="status" data-cart-message></div>
        @unless($card)
            @if($shortage && ! $unavailable)
                <div class="text-meta text-tone-danger-text" data-refresh="{{ $key }}-available">Доступно {{ $shortage->available }} шт.</div>
            @else
                <div class="hidden" data-refresh="{{ $key }}-available"></div>
            @endif
        @endunless
    </div>

    @unless($card)
        {{-- Наличие --}}
        <div class="pt-5" data-refresh="{{ $key }}-stock">
            @if($unavailable)
                <span class="text-sm text-tone-danger-text">{{ $problems[0]->title() }}</span>
            @else
                <x-stock :product="$product" class="text-sm" />
            @endif
        </div>
    @endunless

    {{-- Розница — сумма с НДС; B2B — сумма без НДС + «с НДС X» --}}
    <div @class(['whitespace-nowrap text-right tabular-nums', 'pt-4' => ! $card]) data-refresh="{{ $key }}-sum">
        @if($unavailable)
            <span class="text-ink-muted">—</span>
        @else
            @if(\App\Services\Vat\VatCalculator::pricesIncludeVat())
                {{-- Розница: сумма с НДС --}}
                <div class="font-bold"><x-price :amount="$line->gross" /></div>
            @else
                <div class="font-bold"><x-price :amount="$line->net" /></div>
                <div class="text-meta text-ink-muted">{{ $card ? 'без НДС' : 'с НДС '.money($line->gross, false) }}</div>
            @endif
        @endif
    </div>

    @if($card)
        </div>
    @else
        <x-confirm
            action="/order/delete/{{ $item->id }}"
            label="Удалить"
            title="Удалить товар из корзины?"
            confirm-label="Удалить"
            icon="trash"
            class="pt-2.5"
        >«{{ $item->title() }}» будет удалён из корзины.</x-confirm>
    @endif
</article>
