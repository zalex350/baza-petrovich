<?php

namespace Tests\Feature\Retail;

use App\Models\Review;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Наши работы» и «Отзывы»: витрина, админка, импорт со старого сайта.
 */
class WorksReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_import_creates_works_with_tags_and_unpublished_reviews_without_images(): void
    {
        $this->artisan('petrovich:works', ['file' => base_path('tests/Fixtures/petrovich/works.json')])->assertSuccessful();
        $this->artisan('petrovich:works', ['file' => base_path('tests/Fixtures/petrovich/works.json')])->assertSuccessful();

        $this->assertSame(2, Work::count());
        $work = Work::where('source_key', 'nashi-raboty/zabor-1')->sole();
        $this->assertSame('Заборы', $work->tag);
        $this->assertSame('Ворота', Work::where('source_key', 'nashi-raboty/vorota')->value('tag'));
        $this->assertStringNotContainsString('<script>', $work->description);

        // Без --images у отзыва нет скана — не публикуется
        $this->assertFalse(Review::sole()->is_published);
    }

    public function test_storefront_pages(): void
    {
        $work = Work::create(['title' => 'Забор из профнастила', 'slug' => 'zabor', 'tag' => 'Заборы', 'location' => 'д. Прудищи', 'description' => '<p>Под ключ</p>', 'is_published' => true]);
        $work->images()->create(['path' => 'works/a.webp']);
        Work::create(['title' => 'Скрытый', 'slug' => 'hidden', 'is_published' => false]);
        Review::create(['author' => 'Андрей', 'text' => 'Всё отлично', 'rating' => 5, 'group' => 'Заборы «под ключ»', 'work_id' => $work->id]);

        $this->get('/nashi-raboty')->assertOk()->assertSee('Забор из профнастила')->assertDontSee('Скрытый')->assertSee('д. Прудищи');
        $this->get('/nashi-raboty?tag=Заборы')->assertOk()->assertSee('Забор из профнастила');
        $this->get('/nashi-raboty/zabor')->assertOk()->assertSee('Под ключ')->assertSee('Всё отлично')->assertSee('data-photo-gallery', false);
        $this->get('/nashi-raboty/hidden')->assertNotFound();
        $this->get('/otzyvy')->assertOk()->assertSee('Заборы «под ключ»')->assertSee('Андрей')->assertSee('Яндекс Картах');
        $this->get('/')->assertOk()->assertSee('data-home-works', false)->assertSee('data-home-reviews', false);
        $this->get('/')->assertSee('href="/nashi-raboty"', false)->assertSee('href="/otzyvy"', false);
    }

    public function test_admin_crud(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/work', [
            'title' => 'Ворота откатные', 'tag' => 'Ворота', 'description' => '<p>Текст</p>', 'is_published' => '1',
            'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600), UploadedFile::fake()->image('b.jpg', 800, 600)],
        ])->assertRedirect();

        $work = Work::sole();
        $this->assertSame('vorota-otkatnye', $work->slug);
        $this->assertSame(2, $work->images()->count());

        $second = $work->images()->get()[1];
        $this->actingAs($admin)->post("/admin/work/{$work->id}/image/{$second->id}/cover")->assertRedirect();
        $this->assertSame($second->id, $work->fresh()->cover->id);

        $this->actingAs($admin)->get('/admin/works')->assertOk()->assertSee('Ворота откатные');

        $this->actingAs($admin)->post('/admin/review', ['author' => 'Ольга'])->assertSessionHasErrors('text');
        $this->actingAs($admin)->post('/admin/review', ['author' => 'Ольга', 'text' => 'Спасибо', 'rating' => 5, 'work_id' => $work->id, 'is_published' => '1'])->assertRedirect('/admin/reviews');
        $this->assertSame($work->id, Review::sole()->work_id);

        $this->actingAs($admin)->post("/admin/work/{$work->id}/delete")->assertRedirect('/admin/works');
        $this->assertSame(0, Work::count());
        $this->assertNull(Review::sole()->work_id);
    }
}
