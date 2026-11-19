<?php

namespace App\Models;

use App\Services\PageTargets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Код стороннего сервиса (квиз MoClients / Marquiz, чат JivoSite, обратный звонок, Яндекс.Метрика, GTM…):
 * вставляется как есть в <head> (position head) или перед </body> (body) на страницах targets
 * (App\Services\PageTargets). Правит только администратор — код выполняется на сайте.
 */
class SiteScript extends Model
{
    public const POSITIONS = ['head' => 'В <head> (счётчики, пиксели)', 'body' => 'Перед </body> (виджеты, квизы, чаты)'];

    protected $fillable = ['name', 'code', 'position', 'targets', 'is_active', 'sort_order'];

    protected $casts = [
        'targets' => 'array',
        'is_active' => 'boolean',
    ];

    /** @return Collection<int, SiteScript> */
    public static function for(string $position): Collection
    {
        $targets = app(PageTargets::class);

        if ($targets->current() === []) {
            return collect();
        }

        $all = app()->bound('site_scripts.all')
            ? app('site_scripts.all')
            : tap(static::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(), fn ($c) => app()->instance('site_scripts.all', $c));

        return $all->where('position', $position)->filter(fn (SiteScript $s) => $targets->matches($s->targets))->values();
    }
}
