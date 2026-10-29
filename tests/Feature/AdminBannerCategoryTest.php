<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Админка: баннеры главной (картинки десктоп + мобильная в webp, ссылка, порядок, вкл/выкл)
 * и картинка категории (webp).
 */
class AdminBannerCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.images.disk' => 'public']);
        Storage::fake('public');

        $this->admin = User::factory()->admin()->create();
    }

    private function image(string $name = 'banner.jpg', int $w = 1200, int $h = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    public function test_admin_pages_open_and_are_in_menu(): void
    {
        foreach (['/admin/banners', '/admin/banner/create', '/admin/categories', '/admin/pages', '/admin/settings', '/admin/partners'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $this->actingAs($this->admin)->get('/admin/banners')
            ->assertSee('href="/admin/banners"', false)
            ->assertSee('href="/admin/categories"', false)
            ->assertSee('href="/admin/pages"', false)
            ->assertSee('href="/admin/settings"', false)
            ->assertSee('href="/admin/partners"', false);
    }

    public function test_client_cannot_manage_banners(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/admin/banners')->assertForbidden();
        $this->actingAs($client)->post('/admin/banner', [])->assertForbidden();
    }

    public function test_create_banner_stores_webp_images(): void
    {
        $this->actingAs($this->admin)->post('/admin/banner', [
            'title' => 'Скидки на смесители',
            'url' => '/promo',
            'sort_order' => 3,
            'is_active' => '1',
            'image_desktop' => $this->image(),
            'image_mobile' => $this->image('m.png', 600, 400),
        ])->assertRedirect('/admin/banners')->assertSessionHasNoErrors();

        $banner = Banner::sole();

        $this->assertSame('/promo', $banner->url);
        $this->assertTrue($banner->is_active);
        $this->assertStringEndsWith('-desktop.webp', $banner->image_desktop);
        $this->assertStringEndsWith('-mobile.webp', $banner->image_mobile);
        Storage::disk('public')->assertExists([$banner->image_desktop, $banner->image_mobile]);
    }

    public function test_banner_validation(): void
    {
        $this->actingAs($this->admin)->post('/admin/banner', [
            'url' => 'javascript:alert(1)',
            'sort_order' => '',
        ])->assertSessionHasErrors(['image_desktop', 'url', 'sort_order']);

        $this->actingAs($this->admin)->post('/admin/banner', [
            'sort_order' => 1,
            'image_desktop' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('image_desktop');

        $this->assertSame(0, Banner::count());
    }

    public function test_update_replaces_image_and_deletes_old_file(): void
    {
        $this->actingAs($this->admin)->post('/admin/banner', ['sort_order' => 1, 'is_active' => '1', 'image_desktop' => $this->image()]);
        $banner = Banner::sole();
        $old = $banner->image_desktop;

        $this->actingAs($this->admin)->post("/admin/banner/{$banner->id}", [
            'sort_order' => 5, 'is_active' => '0', 'image_desktop' => $this->image('new.jpg'),
        ])->assertSessionHasNoErrors();

        $banner->refresh();
        $this->assertNotSame($old, $banner->image_desktop);
        $this->assertSame(5, $banner->sort_order);
        $this->assertFalse($banner->is_active);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($banner->image_desktop);
    }

    public function test_toggle_and_delete_banner(): void
    {
        $this->actingAs($this->admin)->post('/admin/banner', ['sort_order' => 1, 'is_active' => '1', 'image_desktop' => $this->image()]);
        $banner = Banner::sole();

        $this->actingAs($this->admin)->post("/admin/banner/{$banner->id}/toggle");
        $this->assertFalse($banner->fresh()->is_active);

        $this->actingAs($this->admin)->post("/admin/banner/{$banner->id}/delete")->assertRedirect('/admin/banners');
        $this->assertSame(0, Banner::count());
        Storage::disk('public')->assertMissing($banner->image_desktop);
    }

    public function test_category_image_upload_and_delete(): void
    {
        $category = Category::create(['name' => 'Для кухни', 'slug' => 'kitchen']);

        $this->actingAs($this->admin)->from('/admin/categories')
            ->post("/admin/category/{$category->id}/image", ['image' => $this->image('c.jpg', 800, 800)])
            ->assertRedirect('/admin/categories')
            ->assertSessionHas('success');

        $path = $category->fresh()->image;
        $this->assertStringStartsWith('categories/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->admin)->get('/admin/categories')->assertSee('data-category-image', false);

        $this->actingAs($this->admin)->post("/admin/category/{$category->id}/image/delete");
        $this->assertNull($category->fresh()->image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_category_image_error_names_category(): void
    {
        $category = Category::create(['name' => 'Для кухни', 'slug' => 'kitchen']);

        $this->actingAs($this->admin)->from('/admin/categories')
            ->post("/admin/category/{$category->id}/image", [])
            ->assertSessionHas('error', '«Для кухни»: Выберите файл');
    }
}
