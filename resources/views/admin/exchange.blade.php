{{--
    «Обмен с 1С» (docs/1C_EXCHANGE_SPEC.md, разделы 2 и 8): вкладки журнал, токены, IP, настройки.
    Данные: $tab; jobs — $jobs, $filters; tokens — $tokens, $newToken (только сразу после создания);
    ips — $ips, $currentIp; settings — $settings.
--}}
@extends('layouts.admin')

@section('title', 'Обмен с 1С')

@section('flash-validation', 'summary')

@section('content')

<h1 class="text-3xl font-bold mb-2">Обмен с 1С</h1>

<p class="text-sm text-ink-muted mb-6 max-w-3xl">
    1С обращается к сайту по адресу <code>{{ url('/api/1c/v1') }}</code> с токеном и только с адресов из списка IP.
    Каждый принятый файл — задание в журнале. Письма о сбоях получают администраторы с галочкой «Сбои обмена с 1С».
</p>

<x-tabs label="Разделы обмена" :active="$tab" :tabs="[
    'jobs' => ['label' => 'Журнал', 'url' => '/admin/exchange'],
    'tokens' => ['label' => 'Токены', 'url' => '/admin/exchange?tab=tokens'],
    'ips' => ['label' => 'IP-адреса', 'url' => '/admin/exchange?tab=ips'],
    'settings' => ['label' => 'Настройки', 'url' => '/admin/exchange?tab=settings'],
]" />

@if($tab === 'jobs')

    <form method="GET" action="/admin/exchange" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end" data-exchange-filters>
        <x-select name="type" label="Тип" placeholder="Все" :options="collect(\App\Enums\ExchangeJobType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :value="$filters['type']" />
        <x-select name="status" label="Статус" placeholder="Все" :options="collect(\App\Enums\ExchangeJobStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :value="$filters['status']" />
        <x-input name="from" type="date" label="С" :value="$filters['from']" />
        <x-input name="to" type="date" label="По" :value="$filters['to']" />
        <div class="flex gap-2">
            <x-button>Показать</x-button>
            @if(array_filter($filters))
                <x-button variant="secondary" href="/admin/exchange">Сбросить</x-button>
            @endif
        </div>
    </form>

    @if($jobs->isEmpty())
        <x-empty-state title="Заданий нет" icon="arrows-right-left">
            @if(array_filter($filters)) Под фильтр ничего не подошло. @else 1С ещё не присылала файлов. @endif
        </x-empty-state>
    @else
        <x-table stack="lg" caption="Журнал обмена">
            <x-slot:head>
                <x-table.th>Получено</x-table.th>
                <x-table.th>Тип</x-table.th>
                <x-table.th>Статус</x-table.th>
                <x-table.th align="right">Строк</x-table.th>
                <x-table.th align="right">Ошибок</x-table.th>
                <x-table.th>Токен, IP</x-table.th>
                <x-table.th><span class="sr-only">Действия</span></x-table.th>
            </x-slot:head>

            @foreach($jobs as $job)
                <tr data-exchange-job="{{ $job->id }}">
                    <x-table.td label="Получено" nowrap>{{ date_ru($job->received_at, time: true) }}</x-table.td>
                    <x-table.td label="Тип">
                        {{ $job->type->label() }}
                        @if($job->mode)<span class="text-meta text-ink-muted">· {{ $job->mode }}</span>@endif
                    </x-table.td>
                    <x-table.td label="Статус"><x-badge :tone="$job->status->tone()">{{ $job->status->label() }}</x-badge></x-table.td>
                    <x-table.td label="Строк" align="right" nowrap>{{ $job->rows_total }}</x-table.td>
                    <x-table.td label="Ошибок" align="right" nowrap>
                        @if($job->rows_error > 0)<span class="font-semibold text-tone-danger-text">{{ $job->rows_error }}</span>@else 0 @endif
                    </x-table.td>
                    <x-table.td label="Токен, IP">
                        <span class="block">{{ $job->token->name ?? '—' }}</span>
                        <span class="block text-meta text-ink-muted">{{ $job->ip ?? '—' }}</span>
                    </x-table.td>
                    <x-table.td><x-button variant="secondary" size="sm" href="/admin/exchange/job/{{ $job->id }}">Открыть</x-button></x-table.td>
                </tr>
            @endforeach
        </x-table>

        <div class="mt-4">
            <x-pagination :paginator="$jobs" />
        </div>
    @endif

@elseif($tab === 'tokens')

    @isset($newToken)
        <x-alert type="success" title="Токен создан" class="mb-6" data-new-token>
            <p class="mb-3">Скопируйте его сейчас и передайте разработчику 1С не по почте — больше он нигде не покажется.</p>
            <x-field id="new-token" label="Токен">
                <x-copy field id="new-token" :text="$newToken" label="Скопировать токен" />
            </x-field>
        </x-alert>
    @endisset

    <form method="POST" action="/admin/exchange/tokens" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 sm:flex sm:items-end">
        @csrf
        <x-input name="name" label="Название нового токена" placeholder="1С — тестовый стенд" maxlength="100" required class="sm:grow" />
        <x-button>Создать токен</x-button>
    </form>

    @if($tokens->isEmpty())
        <x-empty-state title="Токенов нет" icon="key">Создайте токен и передайте его разработчику 1С.</x-empty-state>
    @else
        <x-table stack="lg" caption="Токены API">
            <x-slot:head>
                <x-table.th>Название</x-table.th>
                <x-table.th>Начало токена</x-table.th>
                <x-table.th>Создан</x-table.th>
                <x-table.th>Последний запрос</x-table.th>
                <x-table.th>Статус</x-table.th>
                <x-table.th><span class="sr-only">Действия</span></x-table.th>
            </x-slot:head>

            @foreach($tokens as $token)
                <tr data-exchange-token="{{ $token->id }}">
                    <x-table.td label="Название">{{ $token->name }}</x-table.td>
                    <x-table.td label="Начало токена" nowrap><code>{{ $token->prefix }}…</code></x-table.td>
                    <x-table.td label="Создан">
                        <span class="block">{{ date_ru($token->created_at, time: true) }}</span>
                        <span class="block text-meta text-ink-muted">{{ $token->creator->name ?? '—' }}</span>
                    </x-table.td>
                    <x-table.td label="Последний запрос">
                        @if($token->last_used_at)
                            <span class="block">{{ date_ru($token->last_used_at, time: true) }}</span>
                            <span class="block text-meta text-ink-muted">{{ $token->last_used_ip }}</span>
                        @else
                            —
                        @endif
                    </x-table.td>
                    <x-table.td label="Статус">
                        @if($token->isRevoked())
                            <x-badge tone="neutral">Отозван {{ date_ru($token->revoked_at) }}</x-badge>
                        @else
                            <x-badge tone="success">Действует</x-badge>
                        @endif
                    </x-table.td>
                    <x-table.td>
                        @unless($token->isRevoked())
                            <x-confirm
                                action="/admin/exchange/token/{{ $token->id }}/revoke"
                                label="Отозвать"
                                title="Отозвать токен?"
                                confirm-label="Отозвать"
                                trigger-variant="secondary"
                                trigger-size="sm"
                            >
                                Запросы с токеном «{{ $token->name }}» сразу перестанут приниматься (401). Вернуть его нельзя — только создать новый.
                            </x-confirm>
                        @endunless
                    </x-table.td>
                </tr>
            @endforeach
        </x-table>
    @endif

@elseif($tab === 'ips')

    <x-alert type="info" class="mb-6">
        Запросы принимаются только с этих адресов; пока список пуст, API закрыт для всех (403).
        Ваш текущий адрес: <code>{{ $currentIp }}</code>.
    </x-alert>

    <form method="POST" action="/admin/exchange/ips" class="mb-6 grid gap-4 rounded-card border border-line bg-surface p-4 sm:grid-cols-2 lg:flex lg:items-end">
        @csrf
        <x-input name="ip" label="IP-адрес или подсеть" placeholder="203.0.113.10 или 203.0.113.0/24" maxlength="64" required />
        <x-input name="comment" label="Комментарий" placeholder="Сервер 1С заказчика" maxlength="255" class="lg:grow" />
        <x-button>Добавить</x-button>
    </form>

    @if($ips->isEmpty())
        <x-empty-state title="Список пуст — API закрыт" icon="shield-exclamation">Добавьте внешний IP сервера 1С.</x-empty-state>
    @else
        <x-table stack="lg" caption="Разрешённые IP-адреса">
            <x-slot:head>
                <x-table.th>Адрес</x-table.th>
                <x-table.th>Комментарий</x-table.th>
                <x-table.th>Добавлен</x-table.th>
                <x-table.th><span class="sr-only">Действия</span></x-table.th>
            </x-slot:head>

            @foreach($ips as $ip)
                <tr data-exchange-ip="{{ $ip->id }}">
                    <x-table.td label="Адрес" nowrap><code>{{ $ip->ip }}</code></x-table.td>
                    <x-table.td label="Комментарий">{{ $ip->comment ?: '—' }}</x-table.td>
                    <x-table.td label="Добавлен">
                        <span class="block">{{ date_ru($ip->created_at, time: true) }}</span>
                        <span class="block text-meta text-ink-muted">{{ $ip->creator->name ?? '—' }}</span>
                    </x-table.td>
                    <x-table.td>
                        <x-confirm
                            action="/admin/exchange/ip/{{ $ip->id }}/delete"
                            label="Удалить"
                            title="Удалить адрес?"
                            confirm-label="Удалить"
                            trigger-variant="secondary"
                            trigger-size="sm"
                        >
                            Запросы с {{ $ip->ip }} перестанут приниматься (403).
                        </x-confirm>
                    </x-table.td>
                </tr>
            @endforeach
        </x-table>
    @endif

@else

    <form method="POST" action="/admin/exchange/settings" class="max-w-3xl space-y-6 rounded-card border border-line bg-surface p-card" data-exchange-settings>
        @csrf

        <div class="grid gap-6 sm:grid-cols-2">
            @foreach(\App\Support\ExchangeSettings::FIELDS as $key => [$label, $default, $min, $max])
                <x-input :name="$key" type="number" :min="$min" :max="$max" step="1" :label="$label" :value="$settings[$key]" :hint="'По умолчанию — '.$default" required />
            @endforeach
        </div>

        <x-checkbox
            :name="\App\Support\ExchangeSettings::PENDING_ORDERS_ALERT"
            label="Письма о заказах, не переданных в 1С"
            hint="Включить после подключения выгрузки заказов в 1С — до этого все заказы ждут передачи."
            :checked="\App\Support\ExchangeSettings::pendingOrdersAlert()"
        />

        <x-button>Сохранить</x-button>
    </form>

@endif

@endsection
