{{--
    Розничное оформление гостя (внутри формы /order/checkout): контакт, способ получения, адрес
    (для доставки), оплата, согласие на обработку персональных данных. Способы — config shop.checkout.
    Проверка — CartController::checkoutRetail.
--}}
@php
    $deliveryMethods = collect(config('shop.checkout.delivery'))->filter(fn ($m) => $m['enabled'] ?? true);
    $paymentMethods = collect(config('shop.checkout.payment'))->filter(fn ($m) => $m['enabled'] ?? true);
    $selectedDelivery = old('delivery_method', $deliveryMethods->keys()->first());
    $selectedPayment = old('payment_method', $paymentMethods->keys()->first());
    $needsAddress = $deliveryMethods->filter(fn ($m) => $m['needs_address'] ?? false)->keys()->values();
    $choice = 'flex cursor-pointer items-start gap-2.5 rounded-panel border border-line-strong px-3 py-2.5 has-[:checked]:border-primary has-[:checked]:ring-1 has-[:checked]:ring-primary';
@endphp

<div class="flex flex-col gap-4" x-data="{ delivery: @js($selectedDelivery), needsAddress: @js($needsAddress) }" data-checkout-retail>

    <x-input name="name" label="Имя" required autocomplete="name" maxlength="255" />
    <x-input name="phone" type="tel" label="Телефон" required autocomplete="tel" placeholder="+375 29 123-45-67" maxlength="50" />
    <x-input name="email" type="email" label="Email" hint="Необязательно — пришлём ссылку на заказ" autocomplete="email" maxlength="255" />

    <fieldset class="flex flex-col gap-2" data-delivery-methods>
        <legend class="mb-2 font-semibold">Способ получения <span class="text-danger">*</span></legend>
        @foreach($deliveryMethods as $code => $method)
            <label class="{{ $choice }}">
                <input type="radio" name="delivery_method" value="{{ $code }}" x-model="delivery" class="mt-1 border-line-strong text-primary focus:ring-primary" @checked($selectedDelivery === $code) required>
                <span class="min-w-0">
                    <span class="font-semibold">{{ $method['label'] }}</span>
                    @if(! empty($method['address']))
                        <span class="block text-sm text-ink-muted">{{ $method['address'] }}</span>
                    @endif
                    @if(! empty($method['hint']))
                        <span class="block text-meta text-ink-muted">{{ $method['hint'] }}</span>
                    @endif
                </span>
            </label>
        @endforeach
        @error('delivery_method')
            <p class="text-sm text-tone-danger-text" data-field-error>{{ $message }}</p>
        @enderror
    </fieldset>

    {{-- Адрес — только для доставки (без JS поле видно всегда, сервер требует его только для доставки) --}}
    <div x-show="needsAddress.includes(delivery)" data-delivery-address>
        <x-textarea name="address" label="Адрес доставки" rows="2" maxlength="500" placeholder="Город, улица, дом; ориентир для водителя" />
    </div>

    <fieldset class="flex flex-col gap-2" data-payment-methods>
        <legend class="mb-2 font-semibold">Оплата <span class="text-danger">*</span></legend>
        @foreach($paymentMethods as $code => $method)
            <label class="{{ $choice }}">
                <input type="radio" name="payment_method" value="{{ $code }}" class="mt-1 border-line-strong text-primary focus:ring-primary" @checked($selectedPayment === $code) required>
                <span class="font-semibold">{{ $method['label'] }}</span>
            </label>
        @endforeach
        @error('payment_method')
            <p class="text-sm text-tone-danger-text" data-field-error>{{ $message }}</p>
        @enderror
    </fieldset>

    <x-textarea name="comment" label="Комментарий к заказу" rows="3" maxlength="1000" placeholder="Например: нужна резка в размер, позвонить за час до доставки" />

    <x-checkbox name="consent" value="1" :unchecked="false" required label="Согласен на обработку персональных данных для оформления и доставки заказа" />
    <x-legal-note :oferta="true" />
</div>
