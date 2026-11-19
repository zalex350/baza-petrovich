<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\ProductImage;
use App\Services\Images\WebpImage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Статьи со старого сайта (database/data/petrovich/articles.json, сбор — scraper/articles.py):
 * страницы type = article, /stati/{slug}. Устаревшие новости (skip) не переносятся — их старые адреса
 * ведут на /stati (App\Support\LegacyUrls). Повтор — по source_key, без дублей; текст, исправленный
 * уже перенесённые статьи (их могли поправить в админке) не перезаписываются без --force.
 *
 * --images — фото скачиваются со старого сайта (WebP, до 1600 px) в articles/{id}/, иначе в тексте
 * остаются адреса старого сайта (до переезда домена они работают).
 */
class PetrovichArticles extends Command
{
    protected $signature = 'petrovich:articles
        {--images : скачать фото статей к себе}
        {--force : перезаписать уже перенесённые статьи}';

    protected $description = 'Статьи и советы со старого сайта в раздел «Статьи»';

    public function handle(WebpImage $images): int
    {
        $rows = collect(json_decode((string) file_get_contents(database_path('data/petrovich/articles.json')), true) ?: [])
            ->reject(fn ($row) => ! empty($row['skip']));

        $bar = $this->output->createProgressBar($rows->count());
        $created = $updated = $kept = $photos = 0;

        foreach ($rows as $row) {
            $bar->advance();

            $page = Page::firstOrNew(['source_key' => $row['source_key']]);
            $page->forceFill(['source_key' => $row['source_key']]);

            // Уже перенесена (могли поправить в админке) — не трогаем без --force
            if ($page->exists && ! $this->option('force')) {
                $kept++;

                continue;
            }

            $page->exists ? $updated++ : $created++;

            if (! $page->exists) {
                $page->slug = $this->slug($row['slug'], $row['title']);
                $page->title = Str::limit(self::title($row['title']), 250, '');
                $page->type = Page::ARTICLE;
                $page->is_published = true;
                $page->save(); // id — для папки фото
            }

            $urls = [];

            foreach ($row['images'] ?? [] as $i => $url) {
                $urls[$i] = $url;

                if ($this->option('images')) {
                    $path = $this->download($url, 'articles/'.$page->id, $images);

                    if ($path) {
                        $urls[$i] = Storage::disk(ProductImage::disk())->url($path);
                        $photos++;
                    }
                }
            }

            $body = preg_replace_callback('/\{\{img:(\d+)\}\}/', fn ($m) => isset($urls[$m[1]])
                ? '<img src="'.e($urls[$m[1]]).'" alt="'.e($row['title']).'">'
                : '', $row['html']);

            $page->forceFill([
                'type' => Page::ARTICLE,
                'title' => Str::limit(self::title($row['title']), 250, ''),
                'body' => $body,
                'image' => $urls[0] ?? null,
                'seo_description' => $row['description'] ?? null,
                'published_at' => $row['date'] ? Carbon::parse($row['date']) : now(),
            ])->save();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Статей: новых {$created}, обновлено {$updated}, уже были (не тронуты) {$kept}, фото скачано {$photos}");

        return self::SUCCESS;
    }

    /** Заголовок без КРИКА: «ЗАБОР ИЗ ПРОФНАСТИЛА!!!» → «Забор из профнастила!» */
    public static function title(string $title): string
    {
        $title = preg_replace('/([!?.])\1+/u', '$1', trim($title));
        $letters = preg_replace('/[^\p{L}]/u', '', $title);

        if ($letters !== '' && mb_strtoupper($letters) === $letters && mb_strlen($letters) > 6) {
            $title = mb_strtoupper(mb_substr(mb_strtolower($title), 0, 1)).mb_substr(mb_strtolower($title), 1);
        }

        return $title;
    }

    /** Адрес: старый, если он латиницей, иначе из заголовка; уникальный среди страниц */
    private function slug(string $old, string $title): string
    {
        $base = Str::slug(preg_match('/^[a-z0-9.\-]+$/i', $old) ? str_replace('.', '-', $old) : $title);
        $base = Str::limit(trim($base, '-'), 120, '') ?: 'statya';
        $slug = $base;

        for ($n = 2; Page::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function download(string $url, string $dir, WebpImage $images): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ar');

        try {
            $response = Http::timeout(30)->retry(2, 1000)->withOptions(['sink' => $tmp])->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $images->store(new UploadedFile($tmp, basename(parse_url($url, PHP_URL_PATH) ?: 'photo.jpg'), null, null, true), $dir, 1600);
        } catch (Throwable $e) {
            $this->warn("Фото {$url}: {$e->getMessage()}");

            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
