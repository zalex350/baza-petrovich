{{--
    Блок «Коды клиентов» на странице товара в админке: как называют этот товар клиенты
    (product_client_codes). Один код клиента на товар, код уникален у клиента. Применяется
    сразу, без черновика. Ошибки — под полем (ключи client_code_organization, client_code,
    client_codes.{id}).
    Данные: $product, $clientCodes (ProductClientCode с organization), $codeOrganizations
    (клиенты без кода этого товара: id → название).
--}}
<div id="client-codes" class="bg-white rounded-2xl border border-slate-200 p-6 mb-6 scroll-mt-6" data-admin-client-codes>

    <h2 class="text-lg font-semibold mb-1">
        Коды клиентов
        <span class="text-sm font-normal text-slate-400 ml-2">применяются сразу</span>
    </h2>

    <p class="text-sm text-slate-500 mb-4">
        Артикул этого товара в системе клиента. У клиента один код на товар, и код не повторяется у других его товаров.
        Все коды клиента и загрузка из Excel — в карточке клиента, вкладка «Коды товаров».
    </p>

    @if($clientCodes->isNotEmpty())
        <x-table stack caption="Коды клиентов" class="mb-6">
            <x-slot:head>
                <x-table.th>Клиент</x-table.th>
                <x-table.th>Код клиента</x-table.th>
                <x-table.th><span class="sr-only">Действия</span></x-table.th>
            </x-slot:head>
            @foreach($clientCodes as $code)
                <tr data-client-code="{{ $code->id }}">
                    <x-table.td label="Клиент">
                        <a href="/admin/organization/{{ $code->organization_id }}?tab=codes" class="text-link hover:underline">{{ $code->organization?->name }}</a>
                    </x-table.td>
                    <x-table.td label="Код клиента">
                        <form method="POST" action="/admin/product/{{ $product->id }}/client-codes/{{ $code->id }}" novalidate class="flex flex-wrap items-start gap-2">
                            @csrf
                            <x-input
                                name="client_codes[{{ $code->id }}]"
                                :value="$code->code"
                                maxlength="100"
                                aria-label="Код клиента «{{ $code->organization?->name }}»"
                                class="grow min-w-48"
                            />
                            <x-button variant="secondary" size="sm" class="mt-1">Сохранить</x-button>
                        </form>
                    </x-table.td>
                    <x-table.td align="right">
                        <x-confirm
                            :action="'/admin/product/'.$product->id.'/client-codes/'.$code->id.'/delete'"
                            label="Удалить"
                            title="Удалить код клиента?"
                            confirm-label="Удалить"
                            trigger-variant="link"
                            trigger-size="sm"
                        >
                            Код «{{ $code->code }}» клиента «{{ $code->organization?->name }}» будет удалён у этого товара.
                        </x-confirm>
                    </x-table.td>
                </tr>
            @endforeach
        </x-table>
    @else
        <p class="text-slate-500 mb-4" data-client-codes-empty>Кодов клиентов нет.</p>
    @endif

    @if($codeOrganizations->isNotEmpty())
        <form method="POST" action="/admin/product/{{ $product->id }}/client-codes" novalidate class="grid gap-4 rounded-card border border-line bg-field p-4 md:grid-cols-2" data-client-code-add>
            @csrf
            <x-select name="client_code_organization" label="Клиент" :options="$codeOrganizations" placeholder="Выберите клиента" required />
            <x-input name="client_code" label="Код клиента" maxlength="100" required />
            <div class="md:col-span-2">
                <x-button>Добавить код</x-button>
            </div>
        </form>
    @elseif($clientCodes->isEmpty())
        <p class="text-sm text-slate-500">Клиентов пока нет.</p>
    @endif

</div>
