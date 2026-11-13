<?php

namespace Tests\Feature\Exchange;

use App\Models\ExchangeIp;
use App\Models\ExchangeToken;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /api/1c/v1/catalog/sku-map (docs/1C_EXCHANGE_SPEC.md, раздел 4): CSV «guid;sku» по не удалённым
 * товарам с GUID, по sku; доступ — IP + токен, как у всего API; gzip — по Accept-Encoding.
 */
class SkuMapApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();

        ExchangeIp::create(['ip' => '127.0.0.1']);
        [, $this->plain] = ExchangeToken::issue('1С стенд', null);
    }

    private function map(array $headers = [], string $ip = '127.0.0.1', bool $token = true): TestResponse
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'text/csv'];

        if ($token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->plain;
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('GET', '/api/1c/v1/catalog/sku-map', [], [], [], $server);
    }

    private function product(string $sku, ?string $guid, array $attributes = []): Product
    {
        $product = Product::create($attributes + ['name' => $sku, 'sku' => $sku, 'price' => 1, 'stock' => 0, 'is_active' => true]);

        if ($guid !== null) {
            $product->forceFill(['guid' => $guid])->save();
        }

        return $product;
    }

    public function test_csv_of_products_with_guid_sorted_by_sku(): void
    {
        $this->product('TIM-BL 5810', 'g-tim');
        $this->product('LEDEME-L4755-2', 'g-ledeme');
        // Черновик и скрытый — тоже в карте
        $this->product('AQUA;1', 'g-aqua', ['is_published' => false, 'is_active' => false]);
        // Без GUID и удалённый — нет
        $this->product('NO-GUID', null);
        $this->product('DELETED', 'g-deleted')->delete();

        $response = $this->map()->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeaderMissing('Content-Encoding');

        $this->assertSame(
            "guid;sku\ng-aqua;\"AQUA;1\"\ng-ledeme;LEDEME-L4755-2\ng-tim;TIM-BL 5810\n",
            $response->getContent()
        );
    }

    public function test_gzip_when_client_accepts_it(): void
    {
        $this->product('LEDEME-L4755-2', 'g-ledeme');

        $response = $this->map(['Accept-Encoding' => 'deflate, gzip;q=0.8'])->assertOk()
            ->assertHeader('Content-Encoding', 'gzip')
            ->assertHeader('Vary', 'Accept-Encoding');

        $this->assertSame("guid;sku\ng-ledeme;LEDEME-L4755-2\n", gzdecode($response->getContent()));

        // gzip;q=0 — запрещён
        $this->map(['Accept-Encoding' => 'gzip;q=0'])->assertOk()->assertHeaderMissing('Content-Encoding');
    }

    public function test_empty_map_has_header_only(): void
    {
        $this->assertSame("guid;sku\n", $this->map()->assertOk()->getContent());
    }

    public function test_same_access_as_api(): void
    {
        $this->map(token: false)->assertStatus(401)->assertJsonPath('error', 'unauthorized');
        $this->map(ip: '10.0.0.9')->assertStatus(403)->assertJsonPath('error', 'ip_forbidden');
    }
}
