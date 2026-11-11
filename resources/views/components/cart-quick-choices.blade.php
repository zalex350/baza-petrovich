{{--
    Выбор товара в «Добавить по артикулу», когда артикул есть у нескольких товаров (разные бренды):
    кнопка на товар — «БРЕНД · Арт. … — название»; нажатие отправляет форму x-cart-quick-add
    с product_id этого товара (CartController::quickAdd добавляет именно его).
    <x-cart-quick-choices :choices="$choices" />
    choices — [['id' => …, 'article' => …, 'brand' => …, 'name' => …], …]. Пусто — ничего не выводится.
    Ставится внутри формы; без JS выбор приходит из сессии (CartController::QUICK_CHOICES),
    с JS — в choices_html ответа.
--}}
@props(['choices' => []])

@if($choices)
    <div {{ $attributes->class('mt-2 flex flex-col items-stretch gap-1.5') }} role="group" aria-label="Выберите товар" data-quick-choice-list>
        @foreach($choices as $choice)
            <x-button
                variant="secondary"
                size="sm"
                name="product_id"
                :value="$choice['id']"
                class="!justify-start !whitespace-normal text-left"
                data-quick-choice="{{ $choice['id'] }}"
            >
                <span>
                    <span class="font-semibold">{{ $choice['brand'] ?: 'Без бренда' }}</span>
                    · Арт. {{ $choice['article'] }}
                    <span class="block text-meta font-normal text-ink-muted">{{ $choice['name'] }}</span>
                </span>
            </x-button>
        @endforeach
    </div>
@endif
