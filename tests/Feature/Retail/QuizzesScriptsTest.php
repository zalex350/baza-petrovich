<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Lead;
use App\Models\Page;
use App\Models\Product;
use App\Models\Quiz;
use App\Models\SiteScript;
use App\Models\User;
use App\Services\PageTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Квизы (конструктор, показ по местам, ответы → заявка) и коды сторонних сервисов; меню админки розницы.
 */
class QuizzesScriptsTest extends TestCase
{
    use RefreshDatabase;

    private Category $zabory;

    private Category $sub;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zabory = Category::create(['name' => 'Заборы', 'slug' => 'zabory']);
        $this->sub = Category::create(['name' => 'Забор из профнастила', 'slug' => 'zabor-pn', 'parent_id' => $this->zabory->id]);
        Category::create(['name' => 'Сетка', 'slug' => 'setka']);
        $this->product = Product::create(['name' => 'Забор под ключ', 'sku' => 'Z-1', 'price' => 10, 'stock' => 1, 'category_id' => $this->sub->id, 'is_active' => true, 'is_published' => true]);
    }

    private function quiz(array $attrs = []): Quiz
    {
        return Quiz::create($attrs + [
            'title' => 'Рассчитать забор',
            'display' => 'inline',
            'steps' => Quiz::normalizeSteps([
                ['question' => 'Материал?', 'type' => 'single', 'options' => ['Профнастил', 'Сетка'], 'required' => true],
                ['question' => 'Длина, м?', 'type' => 'number', 'required' => true],
                ['question' => 'Что ещё?', 'type' => 'multi', 'options' => ['Калитка', 'Ворота'], 'required' => false],
            ]),
            'targets' => ['category:'.$this->zabory->id],
            'is_active' => true,
        ]);
    }

    public function test_normalize_steps(): void
    {
        $steps = Quiz::normalizeSteps([
            ['question' => '  ', 'type' => 'single'],
            ['question' => 'Без вариантов', 'type' => 'single', 'options' => ['', ' ']],
            ['question' => 'Тип?', 'type' => 'bad', 'options' => ['A']],
        ]);

        $this->assertCount(2, $steps);
        $this->assertSame('text', $steps[0]['type']);
        $this->assertSame('single', $steps[1]['type']);
    }

    public function test_quiz_shown_by_targets_including_subcategories_and_products(): void
    {
        $this->quiz();
        Quiz::create(['title' => 'Попап', 'display' => 'popup', 'button_label' => 'Посчитать', 'steps' => Quiz::normalizeSteps([['question' => 'Q?', 'type' => 'text']]), 'targets' => ['home'], 'is_active' => true]);

        $this->get('/catalog/zabory')->assertOk()->assertSee('data-quiz-inline', false)->assertSee('Рассчитать забор');
        app(PageTargets::class)->flush();
        $this->get('/catalog/zabor-pn')->assertSee('data-quiz-inline', false);
        app(PageTargets::class)->flush();
        $this->get("/product/{$this->product->id}")->assertSee('data-quiz-inline', false);
        app(PageTargets::class)->flush();
        $this->get('/catalog/setka')->assertDontSee('data-quiz-inline', false);
        app(PageTargets::class)->flush();
        $this->get('/')->assertSee('data-quiz-popup', false)->assertSee('Посчитать')->assertDontSee('data-quiz-inline', false);
    }

    public function test_submit_creates_lead_with_answers(): void
    {
        $quiz = $this->quiz();

        $this->postJson("/quiz/{$quiz->id}", ['answers' => ['Профнастил', '', []], 'name' => 'Иван', 'phone' => '+375291112233', 'consent' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('answers');

        $this->postJson("/quiz/{$quiz->id}", ['answers' => ['Профнастил', '45', ['Калитка', 'Чужое']], 'name' => 'Иван', 'phone' => '+375291112233', 'consent' => 1, 'page' => '/catalog/zabory'])
            ->assertOk()->assertJson(['ok' => true]);

        $lead = Lead::sole();
        $this->assertSame('quiz', $lead->type);
        $this->assertSame($quiz->id, $lead->quiz_id);
        $this->assertSame('Рассчитать забор', $lead->subject);
        $this->assertStringContainsString('Материал?: Профнастил', $lead->comment);
        $this->assertStringContainsString('Длина, м?: 45', $lead->comment);
        $this->assertStringContainsString('Что ещё?: Калитка', $lead->comment);
        $this->assertStringNotContainsString('Чужое', $lead->comment);
        $this->assertSame('/catalog/zabory', $lead->payload['page']);

        $quiz->update(['is_active' => false]);
        $this->postJson("/quiz/{$quiz->id}", ['name' => 'X', 'phone' => '+375291112233', 'consent' => 1])->assertNotFound();
    }

    public function test_site_scripts_in_head_and_body_by_targets(): void
    {
        SiteScript::create(['name' => 'Метрика', 'code' => '<script>window.METRIKA=1</script>', 'position' => 'head', 'targets' => ['all'], 'is_active' => true]);
        SiteScript::create(['name' => 'MoClients', 'code' => '<script src="https://moclients.com/js/10640.js"></script>', 'position' => 'body', 'targets' => ['category:'.$this->zabory->id], 'is_active' => true]);
        SiteScript::create(['name' => 'Выключен', 'code' => '<script>OFF</script>', 'position' => 'body', 'targets' => ['all'], 'is_active' => false]);

        $html = $this->get('/catalog/zabor-pn')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'window.METRIKA=1'));
        $this->assertGreaterThan(strpos($html, '</main>'), strpos($html, 'moclients.com/js/10640.js'));
        $this->assertStringNotContainsString('OFF', $html);

        app()->forgetInstance('site_scripts.all');
        app(PageTargets::class)->flush();
        $this->get('/')->assertSee('window.METRIKA=1', false)->assertDontSee('moclients.com', false);

        // В админке коды не выводятся
        app()->forgetInstance('site_scripts.all');
        app(PageTargets::class)->flush();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')->assertDontSee('window.METRIKA=1', false);
    }

    public function test_admin_quiz_and_script_forms(): void
    {
        $admin = User::factory()->admin()->create();
        Page::create(['title' => 'Доставка', 'slug' => 'dostavka', 'type' => Page::SERVICE, 'is_published' => true]);

        $this->actingAs($admin)->get('/admin/quiz/create')->assertOk()->assertSee('data-targets-picker', false)->assertSee('Заборы')->assertSee('service:dostavka', false);

        $this->actingAs($admin)->post('/admin/quiz', ['title' => 'Q', 'display' => 'inline', 'steps' => '[]', 'targets' => ['home']])->assertSessionHasErrors('steps');
        $this->actingAs($admin)->post('/admin/quiz', [
            'title' => 'Калькулятор', 'display' => 'both', 'is_active' => '1',
            'steps' => json_encode([['question' => 'Материал?', 'type' => 'single', 'options' => ['A', 'B'], 'required' => true]]),
            'targets' => ['home', 'category:'.$this->zabory->id],
        ])->assertRedirect();
        $quiz = Quiz::sole();
        $this->assertSame(['home', 'category:'.$this->zabory->id], $quiz->targets);
        $this->actingAs($admin)->get("/admin/quiz/{$quiz->id}")->assertOk()->assertSee('Как видит покупатель');

        $this->actingAs($admin)->post('/admin/site-script', ['name' => 'Jivo', 'code' => '<script>jivo</script>', 'position' => 'body', 'targets' => ['all'], 'is_active' => '1'])->assertRedirect('/admin/site-scripts');
        $this->assertSame(1, SiteScript::count());
    }

    public function test_retail_admin_menu_hides_b2b(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin/products')
            ->assertSee('Квизы')->assertSee('Коды и виджеты')->assertSee('Наши работы')->assertSee('Отзывы')
            ->assertDontSee('href="/admin/price-types"', false);
    }

    public function test_default_quizzes_command(): void
    {
        $this->zabory->forceFill(['source_key' => 'zabory'])->save();

        $this->artisan('petrovich:quizzes')->assertSuccessful();
        $this->assertSame(2, Quiz::count());
        $this->assertContains('category:'.$this->zabory->id, Quiz::orderBy('sort_order')->first()->targets);

        $this->artisan('petrovich:quizzes')->expectsOutputToContain('уже есть');
        $this->assertSame(2, Quiz::count());
    }
}
