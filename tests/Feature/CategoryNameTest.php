<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Support\CategoryName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Названия категорий — с заглавной первой буквы (CategoryName, categories:capitalize).
 */
class CategoryNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_capitalizes_only_first_letter(): void
    {
        $this->assertSame('Смесители для биде', CategoryName::capitalize('смесители для биде'));
        $this->assertSame('Ёршики', CategoryName::capitalize('ёршики'));
        $this->assertSame('PEX-трубы', CategoryName::capitalize('PEX-трубы'));
        $this->assertSame('Краны ГВС', CategoryName::capitalize('Краны ГВС'));
        $this->assertSame('', CategoryName::capitalize(''));
    }

    public function test_command_renames_keeps_slug_and_is_idempotent(): void
    {
        $lower = Category::create(['name' => 'смесители для биде', 'slug' => 'smesiteli-dlya-bide']);
        $ok = Category::create(['name' => 'Краны', 'slug' => 'krany']);

        // dry-run — только список
        $this->artisan('categories:capitalize', ['--dry-run' => true])
            ->expectsOutputToContain('Будет переименовано: 1')
            ->assertSuccessful();
        $this->assertSame('смесители для биде', $lower->fresh()->name);

        $this->artisan('categories:capitalize')->expectsOutputToContain('Переименовано: 1')->assertSuccessful();
        $this->assertSame('Смесители для биде', $lower->fresh()->name);
        $this->assertSame('smesiteli-dlya-bide', $lower->fresh()->slug);
        $this->assertSame('Краны', $ok->fresh()->name);

        $this->artisan('categories:capitalize')
            ->expectsOutputToContain('Все названия категорий уже с заглавной буквы.')
            ->assertSuccessful();
    }
}
