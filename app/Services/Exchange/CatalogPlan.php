<?php

namespace App\Services\Exchange;

/**
 * План загрузки номенклатуры (CatalogHandler::plan): что будет записано, без записи.
 * Для пробного прогона (dry_run=1) и для самой записи.
 */
class CatalogPlan
{
    /** @var array<int, array<string, mixed>> id товара => колонки products для записи */
    public array $updates = [];

    /** @var array<int, array<string, mixed>> номер строки => атрибуты нового товара (черновик) */
    public array $creates = [];

    /** Сопоставлено строк: по GUID / по SKU сайта / по «бренд + артикул» (GUID присваивается) */
    public int $byGuid = 0;

    public int $bySku = 0;

    public int $byKey = 0;

    /** Товаров, у которых меняются поля (кроме отметки времени обмена) */
    public int $updated = 0;

    /** Строк, применённых без изменения значений (обновится только synced_at) */
    public int $unchanged = 0;

    /** @var array<string, int> поле => у скольких товаров изменится */
    public array $fields = [];

    /** @var list<int> товары, которые скроются (active = 0) */
    public array $hidden = [];

    /** @var list<int> товары, скрытые раньше по active = 0, которые снова покажутся */
    public array $shown = [];

    /** mode=full: причина срабатывания предохранителя (в пробном прогоне — только отметка, без failed) */
    public ?string $guard = null;

    public function __construct(public ExchangeResult $result) {}

    public function countField(string $field): void
    {
        $this->fields[$field] = ($this->fields[$field] ?? 0) + 1;
    }

    /** @return array<string, mixed> exchange_jobs.report.summary */
    public function summary(): array
    {
        ksort($this->fields);

        return [
            'matched_by_guid' => $this->byGuid,
            'matched_by_sku' => $this->bySku,
            'matched_by_key' => $this->byKey,
            'created' => count($this->creates),
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'fields' => $this->fields,
            'hidden' => count($this->hidden),
            'shown' => count($this->shown),
            'errors' => count($this->result->errors),
            'warnings' => count($this->result->warnings),
            'missing' => count($this->result->missing),
            'unmatched' => count($this->result->unmatched),
        ] + ($this->guard !== null ? ['guard' => $this->guard] : []);
    }
}
