@extends('layouts.app')

@section('title', $product->title())

{{--
    Карточка товара (макет docs/design/Product), без вкладок: слева галерея, справа бейджи, название,
    артикул, производитель, карточка цены и покупки, ключевые характеристики; ниже — характеристики
    и описание, справа — документы (если есть). Покупка — x-product-row.buy layout="card"
    («В корзину» без перезагрузки, как в каталоге).
    Данные: ProductController::cardData() — $product, $breadcrumbs, $keyRows, $brandUrl, $inCart, $documents;
    $preview — предпросмотр из админки.
--}}

@section('content')

@isset($preview)

    {{-- Предпросмотр из админки: страница как на сайте, с черновыми текстами --}}
    <div class="mb-6 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 flex flex-wrap items-center gap-3 text-sm" data-preview-bar>

        <strong class="text-amber-800">Предпросмотр</strong>

        <span class="text-amber-700">
            @if(! $product->is_published)
                Товар ещё не опубликован — покупатели его не видят.
            @elseif($preview['draft'])
                Показан неопубликованный черновик текстов.
            @else
                Так товар выглядит на сайте.
            @endif
        </span>

        <span class="grow"></span>

        <a href="/admin/product/{{ $product->id }}" class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-amber-100">
            ← К редактированию
        </a>

        @if(! $product->is_published || $preview['draft'])
            <form method="POST" action="/admin/product/{{ $product->id }}/publish">
                @csrf
                <button type="submit" class="rounded-lg bg-green-700 hover:bg-green-600 text-white px-3 py-1.5">
                    Опубликовать
                </button>
            </form>
        @endif

    </div>

@endisset

@php
    $name = $product->title();

    // BR-082: ошибка в характеристиках не роняет карточку — блок просто не показывается
    $specRows = rescue(fn () => $product->specRows(), collect());
    $specText = trim((string) $product->specifications);
    // Сгенерированное описание на сайте не показывается, пока его не одобрят в админке
    $description = $product->description_generated ? '' : trim((string) $product->description);
    // «Упаковка и логистика» — свёрнутым блоком; служебные (ТН ВЭД) — только в админке
    $logisticsRows = rescue(fn () => $product->specRows('logistics'), collect());
    // «Комплектация»: поле товара, если пусто — характеристика (в таблице характеристик её нет)
    $package = rescue(fn () => $product->packageContents()['text'], '');
    $hasSpecs = $specRows->isNotEmpty() || $specText !== '';
    $hasDetails = $hasSpecs || $description !== '' || $package !== '' || $logisticsRows->isNotEmpty();
@endphp

<div class="flex flex-col gap-6">

    @if(! isset($preview) && \App\Support\Analytics::config())
        @php($trackItem = \App\Support\Analytics::item($product))
        <x-track event="view_item" :params="['value' => $trackItem['price'] ?? 0, 'items' => [$trackItem]]" />
    @endif

    <x-breadcrumbs :items="$breadcrumbs" data-back-to-list />

    <div class="grid gap-8 lg:grid-cols-2 lg:items-start">

        {{-- Галерея: основное фото, миниатюры, увеличенный просмотр --}}
        <div class="min-w-0">
            @include('partials.product_gallery')
        </div>

        <div class="flex min-w-0 flex-col gap-4">

            <div>
                <x-product-badge :product="$product" class="mb-2" />

                <h1 class="text-2xl font-bold leading-tight tracking-tight sm:text-3xl">{{ $name }}</h1>

                <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-ink-muted">
                    <span data-sku>
                        Арт. <x-copy :text="$product->displayArticle()" label="Скопировать артикул" />
                    </span>

                    @if($clientCode = client_code($product))
                        <span class="text-ink" data-client-code>
                            Ваш код: <x-copy :text="$clientCode" label="Скопировать ваш код" />
                        </span>
                    @endif

                    @if($product->brand)
                        <span>Производитель: <a href="{{ $brandUrl }}" class="text-link hover:underline" data-brand-link>{{ $product->brand }}</a></span>
                    @endif
                </div>
            </div>

            {{-- Цена и заказ --}}
            <section aria-label="Цена и заказ" class="flex flex-col gap-4 rounded-card border border-line bg-surface p-5" data-buy-card>

                <div class="flex flex-wrap items-end justify-between gap-3 tabular-nums">
                    <x-product-price :product="$product" layout="card" />

                    @if(prices_visible())
                        <x-stock :product="$product" />
                    @endif
                </div>

                <x-product-row.buy :product="$product" :in-cart="$inCart" layout="card" />

                {{-- Эффективный минимум: min_qty, округлённый вверх до кратности (OrderQuantity) --}}
                @php($qtyRule = \App\Support\OrderQuantity::for($product))
                {{-- Розница: без особых правил (1 / 1) строка не нужна; единица — товара --}}
                @if(! config('shop.retail') || $qtyRule->first > 1 || $qtyRule->step > 1)
                    <div class="text-meta text-ink-muted" data-qty-rules>
                        Мин. заказ {{ $qtyRule->first }} {{ unit_label($product->unit) }} · кратность {{ $qtyRule->step }} {{ unit_label($product->unit) }}
                    </div>
                @endif

            </section>

            {{-- Коротко: ключевые характеристики --}}
            @if($keyRows->isNotEmpty())
                <section aria-label="Коротко" class="grid grid-cols-2 gap-x-6 gap-y-2.5 text-sm" data-key-rows>
                    @foreach($keyRows as $row)
                        <div class="min-w-0">
                            <div class="text-ink-muted">{{ $row['name'] }}</div>
                            <div class="font-medium">{{ $row['value'] }}</div>
                        </div>
                    @endforeach
                </section>
            @endif

            @if($hasSpecs)
                <a href="#specs" class="self-start text-sm text-link hover:underline">Все характеристики</a>
            @endif

        </div>

    </div>

    @if($hasDetails || $documents->isNotEmpty())

        <div @class(['grid items-start gap-6', 'lg:grid-cols-[minmax(0,1fr)_24rem]' => $hasDetails && $documents->isNotEmpty()])>

            @if($hasDetails)
                <section id="specs" aria-label="Характеристики и описание" class="min-w-0 scroll-mt-24 rounded-card border border-line bg-surface px-6 py-5">

                    {{-- «Характеристика — Значение» в заданном порядке, пустые не выводятся (BR-062); нет — нет и блока (§4.9.4) --}}
                    @if($hasSpecs)
                        <div data-specs>
                            <h2 class="mb-3 text-xl font-bold">Характеристики</h2>

                            @if($specRows->isNotEmpty())
                                <dl class="grid grid-cols-2">
                                    @foreach($specRows as $row)
                                        <dt class="border-t border-line-soft py-2.5 pr-4 text-ink-muted" data-spec-row>{{ $row['name'] }}</dt>
                                        <dd class="border-t border-line-soft py-2.5">{{ $row['value'] }}</dd>
                                    @endforeach
                                </dl>
                            @endif

                            @if($specText !== '')
                                <p @class(['text-ink', 'mt-4' => $specRows->isNotEmpty()])>
                                    {!! nl2br(e($specText)) !!}
                                </p>
                            @endif
                        </div>
                    @endif

                    @if($description !== '')
                        <h2 @class(['mb-2 text-xl font-bold', 'mt-6' => $hasSpecs])>Описание</h2>
                        @if(str_contains($description, '<'))
                            {{-- HTML (импорт с baza-petrovich.by) — очищенный --}}
                            <div class="prose-page max-w-prose text-ink" data-description>{!! \App\Support\Html::clean($description) !!}</div>
                        @else
                            <p class="max-w-prose text-ink" data-description>{!! nl2br(e($description)) !!}</p>
                        @endif
                    @endif

                    @if($package !== '')
                        <h2 @class(['mb-2 text-xl font-bold', 'mt-6' => $hasSpecs || $description !== ''])>Комплектация</h2>
                        <p class="max-w-prose whitespace-pre-line break-words text-ink" data-package>{{ $package }}</p>
                    @endif

                    @if($logisticsRows->isNotEmpty())
                        <details @class(['group', 'mt-6' => $hasSpecs || $description !== '' || $package !== '']) data-logistics>
                            <summary class="flex cursor-pointer list-none items-center gap-2 text-xl font-bold">
                                Упаковка и логистика
                                @svg('heroicon-o-chevron-down', 'h-5 w-5 text-ink-muted transition group-open:rotate-180')
                            </summary>
                            <dl class="mt-3 grid grid-cols-2">
                                @foreach($logisticsRows as $row)
                                    <dt class="border-t border-line-soft py-2.5 pr-4 text-ink-muted">{{ $row['name'] }}</dt>
                                    <dd class="border-t border-line-soft py-2.5">{{ $row['value'] }}</dd>
                                @endforeach
                            </dl>
                        </details>
                    @endif

                </section>
            @endif

            {{-- Документы: только если есть --}}
            @if($documents->isNotEmpty())
                <aside aria-label="Документы" class="flex min-w-0 flex-col gap-1 rounded-card border border-line bg-surface px-6 py-5" data-documents>
                    <h2 class="mb-2 text-xl font-bold">Документы</h2>
                    {{-- Иконка типа, название, тип и размер; PDF открывается в новой вкладке, «Скачать» — файлом --}}
                    @foreach($documents as $document)
                        <div class="flex items-center gap-3 border-t border-line-soft py-2.5" data-document>
                            @svg('heroicon-o-'.$document['icon'], 'h-6 w-6 shrink-0 text-danger')
                            <a href="{{ $document['url'] }}" class="flex min-w-0 grow flex-col leading-snug hover:text-link" target="_blank" rel="noopener">
                                <span class="break-words">{{ $document['title'] }}</span>
                                <span class="text-meta text-ink-muted">{{ $document['meta'] }}</span>
                            </a>
                            <a href="{{ $document['url'] }}" download class="flex h-10 w-10 shrink-0 items-center justify-center rounded-control text-ink-muted hover:bg-canvas hover:text-link" aria-label="Скачать: {{ $document['title'] }}" title="Скачать" data-document-download>
                                @svg('heroicon-o-arrow-down-tray', 'h-5 w-5')
                            </a>
                        </div>
                    @endforeach
                </aside>
            @endif

        </div>

    @endif

</div>

@endsection
