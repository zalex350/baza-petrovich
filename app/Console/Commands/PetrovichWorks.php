<?php

namespace App\Console\Commands;

use App\Models\Review;
use App\Models\Work;
use App\Services\Images\WebpImage;
use App\Support\Html;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * «Наши работы» и «Отзывы» со старого сайта (database/data/petrovich/works.json):
 * объекты с текстом и фото, отзывы — сканы по группам. Повтор — по source_key, без дублей;
 * фото качаются только для объектов и отзывов без фото (--images).
 */
class PetrovichWorks extends Command
{
    protected $signature = 'petrovich:works {file? : JSON (по умолчанию database/data/petrovich/works.json)} {--images : скачать фото с baza-petrovich.by}';

    protected $description = 'Импорт «Наших работ» и отзывов со старого сайта';

    /** Метка объекта по названию — для фильтра на /nashi-raboty */
    private const TAGS = [
        '/кровл|перекрыти/u' => 'Кровля',
        '/^(забор|огражден|комбинир|переделк|установк|монтаж)|забор|огражден/u' => 'Заборы',
        '/ворот/u' => 'Ворота',
        '/калитк/u' => 'Калитки',
    ];

    public function handle(WebpImage $images): int
    {
        $data = json_decode((string) @file_get_contents($this->argument('file') ?: database_path('data/petrovich/works.json')), true);

        if (! is_array($data)) {
            $this->error('Не прочитан works.json');

            return self::FAILURE;
        }

        $photos = 0;

        foreach ($data['works'] ?? [] as $i => $row) {
            $work = Work::firstOrNew(['source_key' => $row['source_key']]);
            $work->forceFill(['source_key' => $row['source_key']]);
            $work->fill([
                'title' => $row['title'],

                'description' => trim(Html::clean($row['description'] ?? '')) ?: null,
                // Метка — только если не задана вручную в админке
                'tag' => $work->tag ?: $this->tag($row['title']),
                'sort_order' => $i,
                'is_published' => true,
            ]);

            if (! $work->exists) {
                $work->slug = $this->slug($row['title']);
            }

            $work->save();

            if ($this->option('images') && ! $work->images()->exists()) {
                foreach (array_slice($row['images'] ?? [], 0, 20) as $n => $url) {
                    if ($path = $this->download($url, 'works', $images)) {
                        $work->images()->create(['path' => $path, 'sort_order' => $n]);
                        $photos++;
                    }
                }
            }
        }

        foreach ($data['reviews'] ?? [] as $i => $row) {
            $review = Review::firstOrNew(['source_key' => $row['source_key']]);
            $review->forceFill(['source_key' => $row['source_key']]);
            $review->fill(['group' => $row['group'], 'sort_order' => $i, 'is_published' => true]);

            if ($this->option('images') && ! $review->image) {
                $review->image = $this->download($row['image'], 'reviews', $images);
                $photos += $review->image ? 1 : 0;
            }

            // Без текста и без фото отзыв не показывается
            if (! $review->image && ! $review->text) {
                $review->is_published = false;
            }

            $review->save();
        }

        $this->info(sprintf('Работ: %d, отзывов: %d, фото: %d', count($data['works'] ?? []), count($data['reviews'] ?? []), $photos));

        return self::SUCCESS;
    }

    private function tag(string $title): ?string
    {
        foreach (self::TAGS as $pattern => $tag) {
            if (preg_match($pattern, mb_strtolower($title))) {
                return $tag;
            }
        }

        return null;
    }

    private function slug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 120, '') ?: 'work';
        $slug = $base;

        for ($n = 2; Work::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function download(string $url, string $dir, WebpImage $images): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wk');

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
