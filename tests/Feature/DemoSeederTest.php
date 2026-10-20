<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Демо-данные стенда (DemoSeeder): только не в production, без дублей при повторе.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo()
    {
        return $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
    }

    public function test_creates_organizations_users_and_orders_in_all_statuses(): void
    {
        $this->seedDemo()
            ->expectsOutputToContain('Демо-учётки')
            ->expectsOutputToContain('admin@demo.test')
            ->expectsOutputToContain('ivanov@demo.test')
            ->assertSuccessful();

        $this->assertSame(2, Organization::where('comment', 'Демо-данные тестового стенда')->count());
        $this->assertSame(3, DeliveryAddress::count());

        $clients = User::whereIn('email', ['ivanov@demo.test', 'petrova@demo.test', 'sidorenko@demo.test'])->get();
        $this->assertCount(2, $clients->pluck('organization_id')->unique());
        $this->assertTrue(User::where('email', 'admin@demo.test')->first()->isAdmin());
        $this->assertNotNull(User::where('email', 'blocked@demo.test')->first()->blocked_at);

        $orders = Order::where('comment', 'like', DemoSeeder::MARK . '%')->get();

        // Все статусы, суммы посчитаны, история записана
        $this->assertEqualsCanonicalizing(
            OrderStatus::values(),
            $orders->pluck('status')->map->value->unique()->values()->all()
        );
        $this->assertTrue($orders->every(fn ($o) => $o->number && $o->total_with_vat > 0 && $o->items()->exists()));
        $this->assertSame(1, $orders->whereNotNull('cancel_requested_at')->count());
        $this->assertSame(OrderStatus::New, $orders->firstWhere('cancel_requested_at', '!=', null)->status);
    }

    public function test_printed_password_works_and_blocked_user_cannot_log_in(): void
    {
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $output = Artisan::output();

        $password = fn (string $email) => preg_match('/' . preg_quote($email, '/') . '\s*\|[^|]+\|\s*(\S+)\s*\|/u', $output, $m) ? $m[1] : null;

        $this->assertNotNull($password('ivanov@demo.test'), $output);

        $this->post('/login', ['email' => 'ivanov@demo.test', 'password' => $password('ivanov@demo.test')])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs(User::where('email', 'ivanov@demo.test')->first());
        $this->post('/logout');

        $this->post('/login', ['email' => 'blocked@demo.test', 'password' => $password('blocked@demo.test')])
            ->assertSessionHasErrors(['email' => 'Учётная запись заблокирована']);
        $this->assertGuest();
    }

    public function test_rerun_does_not_duplicate(): void
    {
        $this->seedDemo()->assertSuccessful();
        $this->seedDemo()->expectsOutputToContain('Демо-заказы уже есть')->assertSuccessful();

        $this->assertSame(8, Order::where('comment', 'like', DemoSeeder::MARK . '%')->count());
        $this->assertSame(2, Organization::where('comment', 'Демо-данные тестового стенда')->count());
        $this->assertSame(1, User::where('email', 'ivanov@demo.test')->count());
    }

    public function test_does_not_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->seedDemo()->expectsOutputToContain('только на тестовом стенде');

        $this->assertSame(0, User::where('email', 'like', '%@demo.test')->count());
        $this->assertSame(0, Order::count());
    }
}
