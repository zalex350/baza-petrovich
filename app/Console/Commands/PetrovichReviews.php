<?php

namespace App\Console\Commands;

use App\Models\Review;
use App\Services\Images\WebpImage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Отзывы Google и Яндекса со скриншотов zabor-petrovich.by (database/data/petrovich/reviews_external.json,
 * фото объектов — review-photos/): текстом, со значком источника, имя — «Имя Ф.». Группа «Отзывы в Google
 * и Яндексе», на сайте — первыми. Повтор — по source_key, без дублей; правленные в админке не трогаются
 * без --force.
 */
class PetrovichReviews extends Command
{
    protected $signature = 'petrovich:reviews {--force : перезаписать уже загруженные}';

    protected $description = 'Отзывы Google и Яндекса (с zabor-petrovich.by) в раздел «Отзывы»';

    public const GROUP = 'Отзывы в Google и Яндексе';

    public function handle(WebpImage $images): int
    {
        $rows = json_decode((string) file_get_contents(database_path('data/petrovich/reviews_external.json')), true) ?: [];
        $created = $kept = 0;

        foreach ($rows as $i => $row) {
            $review = Review::firstOrNew(['source_key' => $row['source_key']]);

            if ($review->exists && ! $this->option('force')) {
                $kept++;

                continue;
            }

            $review->forceFill(['source_key' => $row['source_key']])->fill([
                'author' => $row['author'] ?: null,
                'text' => $row['text'],
                'rating' => $row['rating'],
                'source' => $row['source'],
                'group' => self::GROUP,
                'sort_order' => $i,
                'is_published' => true,
            ]);

            if (! empty($row['photo']) && ! $review->image) {
                $file = database_path('data/petrovich/'.$row['photo']);
                $review->image = $images->store(new UploadedFile($file, basename($file), null, null, true), 'reviews', 1200);
            }

            $review->save();
            $created++;
        }

        $this->info("Отзывов загружено: {$created}, уже были: {$kept}");

        return self::SUCCESS;
    }
}
