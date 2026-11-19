<?php

namespace Tests\Feature\Retail;

use App\Models\Category;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\CurrentCart;
use App\Support\Attribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Аналитика для Google Ads: счётчики из «Настроек», события магазина (x-track, ответ корзины),
 * источник визита (UTM, gclid) в заказе и заявке, UTM в Битрикс24.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Сетка', 'slug' => 'setka']);
        $this->product = Product::create([
            'name' => 'Сетка рабица 50x50', 'sku' => 'SR-1', 'article' => 'SR-1', 'price' => 12.5, 'stock' => 3,
            'category_id' => $category->id, 'is_active' => true, 'is_published' => true,
        ]);
    }

    private function enable(array $values = []): void
    {
        Setting::put($values + ['analytics_ga4_id' => 'G-TEST1234', 'analytics_ads_id' => 'AW-123456789', 'analytics_ads_purchase_label' => 'buyLabel1']);
    }

    public function test_nothing_without_ids(): void
    {
        $this->get('/product/'.$this->product->id)
            ->assertOk()
            ->assertDontSee('window.__analytics', false)
            ->assertDontSee('data-track-event', false)
            ->assertDontSee('data-cookie-banner', false);
    }

    public function test_settings_validate_and_normalize_ids(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('name="analytics_gtm_id"', false)->assertSee('name="seo_product_title"', false);

        $this->actingAs($admin)->post('/admin/settings', ['analytics_gtm_id' => 'UA-123', 'analytics_ym_id' => 'abc'])
            ->assertSessionHasErrors(['analytics_gtm_id', 'analytics_ym_id']);

        $this->actingAs($admin)->post('/admin/settings', [
            'analytics_gtm_id' => ' gtm-ab12cd3 ',
            'analytics_ym_id' => '98765432',
            'analytics_consent' => 'required',
            'google_site_verification' => '<meta name="google-site-verification" content="Abc_DEF-123456" />',
        ])->assertSessionHasNoErrors();

        $this->assertSame('GTM-AB12CD3', Setting::value('analytics_gtm_id'));
        $this->assertSame('Abc_DEF-123456', Setting::value('google_site_verification'));
        $this->assertSame('required', Setting::value('analytics_consent'));
    }

    public function test_gtm_on_storefront_not_for_admins(): void
    {
        Setting::put(['analytics_gtm_id' => 'GTM-AB12CD3', 'analytics_consent' => 'required']);

        $this->get('/')
            ->assertSee('googletagmanager.com/gtm.js', false)
            ->assertSee('GTM-AB12CD3')
            ->assertSee("gtag('consent', 'default'", false)
            ->assertSee('data-cookie-banner="required"', false)
            ->assertDontSee('gtag/js?id=', false);

        $this->actingAs(User::factory()->admin()->create())->get('/')->assertDontSee('GTM-AB12CD3');
    }

    public function test_gtag_mode_and_view_item_event(): void
    {
        $this->enable();

        $html = $this->get('/product/'.$this->product->id)
            ->assertSee('gtag/js?id=G-TEST1234', false)
            ->assertSee('data-cookie-banner="notice"', false)
            ->getContent();

        preg_match('#<script type="application/json" data-track-event="view_item"\s*>(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m, 'событие view_item');
        $params = json_decode($m[1], true);
        $this->assertSame('SR-1', $params['items'][0]['item_id']);
        $this->assertSame(12.5, $params['items'][0]['price']);
        $this->assertSame('Сетка', $params['items'][0]['item_category']);

        $this->get('/catalog/setka')->assertSee('data-track-event="view_item_list"', false);
    }

    public function test_add_to_cart_json_has_event_data(): void
    {
        $this->postJson("/order/add/{$this->product->id}", ['qty' => 2])
            ->assertOk()
            ->assertJsonPath('track.value', 25)
            ->assertJsonPath('track.items.0.item_id', 'SR-1')
            ->assertJsonPath('track.items.0.quantity', 2);
    }

    public function test_order_keeps_source_and_page_sends_purchase_once(): void
    {
        $this->enable();

        // Переход из рекламы — метки в cookie, затем оформление в той же «сессии браузера»
        $landing = $this->get('/catalog/setka?utm_source=google&utm_medium=cpc&utm_campaign=setka&gclid=XYZ');
        $source = $landing->getCookie(Attribution::COOKIE);
        $this->assertNotNull($source);

        $add = $this->withCookie(Attribution::COOKIE, $source->getValue())->post("/order/add/{$this->product->id}", ['qty' => 2]);
        $token = $add->getCookie(CurrentCart::COOKIE)->getValue();

        $response = $this->withCookie(CurrentCart::COOKIE, $token)->withCookie(Attribution::COOKIE, $source->getValue())
            ->post('/order/checkout', [
                'checkout_token' => 'tok-1', 'name' => 'Пётр', 'phone' => '+375 29 150-56-05',
                'delivery_method' => 'delivery', 'address' => 'Минск', 'payment_method' => 'on_receipt', 'consent' => '1',
            ]);

        $order = Order::sole();
        $this->assertSame('google', $order->attribution['utm_source']);
        $this->assertSame('setka', $order->attribution['utm_campaign']);
        $this->assertSame('XYZ', $order->attribution['gclid']);
        $this->assertSame('/catalog/setka', $order->attribution['landing']);

        $html = $this->followRedirects($response)->assertOk()->getContent();
        preg_match('#data-track-event="purchase"\s+data-track-once="([^"]+)"\s*>(.*?)</script>#s', $html, $m);
        $this->assertSame($order->number, $m[1]);
        $purchase = json_decode($m[2], true);
        $this->assertSame($order->number, $purchase['transaction_id']);
        $this->assertSame(25.0, (float) $purchase['value']);
        $this->assertSame('BYN', $purchase['currency']);

        // Повторное открытие ссылки на заказ — без покупки
        $this->get('/o/'.$order->public_token)->assertOk()->assertDontSee('data-track-event="purchase"', false);

        // Источник — в админке
        $this->actingAs(User::factory()->admin()->create())->get('/admin/order/'.$order->id)
            ->assertSee('data-order-source', false)->assertSee('google / cpc · setka · gclid');
    }

    public function test_direct_visit_keeps_previous_source_and_referrer_counts(): void
    {
        $this->get('/', ['referer' => 'https://www.google.com/'])->assertCookie(Attribution::COOKIE);

        $response = $this->get('/');
        $this->assertNull($response->getCookie(Attribution::COOKIE, false), 'прямой заход не затирает источник');

        $data = Attribution::fromRequest(\Illuminate\Http\Request::create('/catalog', 'GET', [], [], [], ['HTTP_REFERER' => 'https://www.google.com/search?q=x']));
        $this->assertSame(['www.google.com', 'organic'], [$data['utm_source'], $data['utm_medium']]);

        $data = Attribution::fromRequest(\Illuminate\Http\Request::create('/catalog?gclid=abc'));
        $this->assertSame(['google', 'cpc'], [$data['utm_source'], $data['utm_medium']]);
    }

    public function test_lead_keeps_source_sends_utm_to_bitrix_and_page_tracks_conversion(): void
    {
        $this->enable();
        config(['services.bitrix24.webhook_url' => 'https://petrovich.bitrix24.by/rest/1/abc/']);
        Http::fake(['*crm.lead.add.json' => Http::response(['result' => 5])]);

        $source = $this->get('/?utm_source=google&utm_medium=cpc&utm_campaign=zabory')->getCookie(Attribution::COOKIE)->getValue();

        $response = $this->withCookie(Attribution::COOKIE, $source)->from('/request/callback')
            ->post(route('lead.store'), ['type' => 'callback', 'name' => 'Анна', 'phone' => '+375291112233', 'consent' => '1']);

        $lead = Lead::sole();
        $this->assertSame('zabory', $lead->attribution['utm_campaign']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'crm.lead.add')
            && $r['fields']['UTM_SOURCE'] === 'google'
            && $r['fields']['UTM_CAMPAIGN'] === 'zabory');

        $this->followRedirects($response)->assertSee('data-track-event="generate_lead"', false);
    }
}
