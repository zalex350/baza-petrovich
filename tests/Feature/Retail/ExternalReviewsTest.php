<?php

namespace Tests\Feature\Retail;

use App\Console\Commands\PetrovichReviews;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Отзывы Google и Яндекса (petrovich:reviews): текстом, со значком источника, первыми — до сканов писем.
 */
class ExternalReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_and_storefront(): void
    {
        Storage::fake('public');
        config(['shop.images.disk' => 'public']);

        // Скан письма (petrovich:works) — должен оказаться после отзывов с карт
        Review::create(['author' => 'Письмо', 'image' => 'reviews/scan.webp', 'group' => 'Заборы «под ключ»', 'is_published' => true, 'sort_order' => 0]);

        Artisan::call('petrovich:reviews');

        $count = Review::where('group', PetrovichReviews::GROUP)->count();
        $this->assertSame(50, $count);
        $this->assertSame(['google', 'yandex'], Review::where('group', PetrovichReviews::GROUP)->distinct()->orderBy('source')->pluck('source')->all());
        $this->assertSame(6, Review::where('group', PetrovichReviews::GROUP)->whereNotNull('image')->count());
        $this->assertSame('Валентина И.', Review::where('source_key', 'zabor/google-04')->value('author'));

        // Повтор — без дублей
        Artisan::call('petrovich:reviews');
        $this->assertSame($count, Review::where('group', PetrovichReviews::GROUP)->count());

        $html = $this->get('/otzyvy')->assertOk()
            ->assertSee('Отзыв в Google')->assertSee('Отзыв в Яндекс')
            ->assertSee('data-reviews-google', false)
            ->assertSee('data-review-photo', false)
            ->getContent();
        $this->assertLessThan(strpos($html, 'Заборы «под ключ»'), strpos($html, PetrovichReviews::GROUP), 'Google и Яндекс — первыми');

        // На главной — отзывы с текстом, не сканы
        $this->get('/')->assertSee('Отзыв в Google')->assertDontSee('data-review-scan', false);
    }
}
