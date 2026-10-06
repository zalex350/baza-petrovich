{{--
    Итог с НДС (B2B): сумма без НДС, НДС, сумма с НДС — суммы по позициям.
    Данные: $totals (App\Services\Vat\VatTotal).
--}}
<dl class="mb-4 grid grid-cols-[auto_auto] justify-start gap-x-6 gap-y-1" data-vat-totals>

    <dt class="text-slate-600">Сумма без НДС:</dt>
    <dd class="text-right text-slate-800">{{ number_format($totals->net, 2, '.', '') }} BYN</dd>

    <dt class="text-slate-600">НДС:</dt>
    <dd class="text-right text-slate-800">{{ number_format($totals->vat, 2, '.', '') }} BYN</dd>

    <dt class="text-2xl font-bold">Сумма с НДС:</dt>
    <dd class="text-2xl font-bold text-right">{{ number_format($totals->gross, 2, '.', '') }} BYN</dd>

</dl>
