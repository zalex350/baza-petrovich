<?php

namespace Tests\Feature\Retail;

use App\Console\Commands\PetrovichIcons;
use App\Models\Category;
use App\Models\Setting;
use App\Services\CategoryIcons;
use App\Services\CategoryTiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Иконки металлобазы (набор metal), их назначение разделам и фото на плитках каталога.
 */
class MetalIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metal_set_is_in_library_and_every_rule_points_to_existing_icon(): void
    {
        $icons = app(CategoryIcons::class);

        $this->assertTrue($icons->exists('metal:profile_pipe'));
        $this->assertNotNull($icons->render('metal:chainlink'));

        foreach (require database_path('data/petrovich/category_icons.php') as $pattern => $icon) {
            $this->assertNotFalse(@preg_match($pattern, ''), "regex {$pattern}");
            $this->assertTrue($icons->exists('metal:'.$icon), "иконка {$icon}");
        }
    }

    public function test_rules_pick_icons_by_name(): void
    {
        $this->assertSame('metal:profile_pipe', PetrovichIcons::iconFor('Профильная труба'));
        $this->assertSame('metal:chainlink', PetrovichIcons::iconFor('Сетка "Рабица"'));
        $this->assertSame('metal:wire_coil', PetrovichIcons::iconFor('Проволока для натяжения сетки'));
        $this->assertSame('metal:welded_mesh', PetrovichIcons::iconFor('Сетка композитная'));
        $this->assertSame('metal:sliding_gate', PetrovichIcons::iconFor('Откатные ворота'));
        $this->assertSame('metal:swing_gate', PetrovichIcons::iconFor('Ворота, калитки, столбы'));
        $this->assertSame('metal:remote', PetrovichIcons::iconFor('Пульт управления Mitto4'));
        $this->assertNull(PetrovichIcons::iconFor('Что-то непонятное'));
    }

    public function test_command_fills_empty_icons_and_inherits_parent(): void
    {
        $root = Category::create(['name' => 'Металлопрокат', 'slug' => 'metalloprokat']);
        $odd = Category::create(['name' => 'Прочее', 'slug' => 'prochee', 'parent_id' => $root->id]);
        $own = Category::create(['name' => 'Уголок', 'slug' => 'ugolok', 'parent_id' => $root->id, 'icon' => 'metal:screw']);

        $this->artisan('petrovich:icons')->assertSuccessful();

        $this->assertSame('metal:profile_pipe', $root->fresh()->icon);
        $this->assertSame('metal:profile_pipe', $odd->fresh()->icon, 'нет правила — иконка родителя');
        $this->assertSame('metal:screw', $own->fresh()->icon, 'заданная вручную не меняется');

        $this->artisan('petrovich:icons', ['--force' => true])->assertSuccessful();
        $this->assertSame('metal:angle', $own->fresh()->icon);
    }

    public function test_content_turns_on_tile_photos(): void
    {
        $this->artisan('petrovich:content')->assertSuccessful();

        $this->assertSame(CategoryTiles::AUTO, Setting::value(CategoryTiles::SETTING));
    }
}
