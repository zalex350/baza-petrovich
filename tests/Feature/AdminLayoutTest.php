<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function adminPages(): array
    {
        $product = Product::create(['name' => 'Grohe', 'sku' => 'GR-1', 'price' => 10, 'stock' => 1]);
        $organization = Organization::factory()->create();
        $buyer = User::factory()->create(['organization_id' => $organization->id]);
        $order = Order::create(['user_id' => $buyer->id, 'status' => Order::STATUS_NEW, 'total' => 10]);

        return [
            '/admin/products',
            '/admin/product/create',
            "/admin/product/{$product->id}",
            '/admin/orders',
            "/admin/order/{$order->id}",
            '/admin/organizations',
            '/admin/organization/create',
            "/admin/organization/{$organization->id}",
            "/admin/organization/{$organization->id}/edit",
        ];
    }

    public function test_admin_pages_use_admin_layout(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ($this->adminPages() as $url) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertSee('data-layout="admin"', false)
                ->assertSee('Админ-панель')
                ->assertSeeInOrder(['Товары', 'Заказы', 'Клиенты'])
                ->assertSee('На сайт')
                // шапка витрины в админке не выводится
                ->assertDontSee('aria-controls="mega-menu"', false);
        }
    }

    public function test_admin_views_extend_admin_layout(): void
    {
        foreach (glob(resource_path('views/admin/*.blade.php')) as $file) {
            $this->assertStringContainsString("@extends('layouts.admin')", file_get_contents($file), basename($file));
        }
    }

    public function test_admin_pages_are_only_for_admins(): void
    {
        $pages = $this->adminPages();
        $client = User::factory()->create();

        foreach ($pages as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        foreach ($pages as $url) {
            $this->actingAs($client)->get($url)->assertForbidden();
        }
    }

    public function test_active_sidebar_item_is_highlighted(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get('/admin/organizations')->getContent();

        $this->assertMatchesRegularExpression('#href="/admin/organizations"[^>]*aria-current="page"#', $html);
        $this->assertDoesNotMatchRegularExpression('#href="/admin/products"[^>]*aria-current="page"#', $html);
    }

    public function test_flash_messages_are_shown_in_admin_layout(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->followingRedirects()
            ->post('/admin/organization', ['name' => 'Новый клиент'])
            ->assertSee('Клиент создан');
    }
}
