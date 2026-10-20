<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderStatusService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Демо-данные для тестового стенда: администратор, три демо-товара, две организации с адресами,
 * клиенты обеих организаций, заблокированный пользователь, заказы во всех статусах
 * и заказ с запросом на отмену. Заказы оформляются так же, как на сайте
 * (CheckoutService, OrderStatusService): номер, суммы, НДС, история статусов.
 *
 * Только staging (и local для проверки): в production не запускается.
 * Повторный запуск не дублирует данные; пароли демо-учёток каждый раз новые —
 * выводятся в консоль.
 *
 * php artisan db:seed --class=DemoSeeder --force
 */
class DemoSeeder extends Seeder
{
    /** Метка демо-заказов в комментарии */
    public const MARK = '[демо]';

    public const ALLOWED_ENVIRONMENTS = ['staging', 'local', 'testing'];

    /** По ним demo:clear находит, что удалять */
    public const EMAIL_DOMAIN = '@demo.test';

    public const ORGANIZATION_UNPS = ['190000001', '290000002'];

    public const PRODUCT_SKUS = ['DEMO-1', 'DEMO-2', 'DEMO-3'];

    public const CATEGORY_SLUG = 'demo-tovary';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> роль, email, имя, пароль */
    private array $accounts = [];

    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->command?->error('DemoSeeder запускается только на тестовом стенде (APP_ENV=staging). Сейчас: ' . app()->environment());

            return;
        }

        $products = $this->products();

        $admin = $this->user('admin@demo.test', 'Администратор (демо)', UserRole::Admin);

        $aqua = $this->organization('ООО «Аква Строй» (демо)', '190000001', 'aqua@demo.test', [
            ['Главный склад', 'г. Минск, ул. Складская, 10', 'Сидоров Пётр', '+375 29 100-00-01', true],
            ['Объект «Новая Боровая»', 'г. Минск, ул. Леонида Щемелёва, 24', 'Кузнецов Олег', '+375 29 100-00-02', false],
        ]);

        $santeh = $this->organization('ЧУП «Сантех-Опт» (демо)', '290000002', 'santeh@demo.test', [
            ['Магазин на Притыцкого', 'г. Минск, ул. Притыцкого, 101', 'Васильева Анна', '+375 33 200-00-01', true],
        ]);

        $ivanov = $this->user('ivanov@demo.test', 'Иванов Иван (Аква Строй)', UserRole::Client, $aqua);
        $petrova = $this->user('petrova@demo.test', 'Петрова Мария (Аква Строй)', UserRole::Client, $aqua);
        $sidorenko = $this->user('sidorenko@demo.test', 'Сидоренко Алексей (Сантех-Опт)', UserRole::Client, $santeh);
        $blocked = $this->user('blocked@demo.test', 'Заблокированный пользователь (Сантех-Опт)', UserRole::Client, $santeh);
        $blocked->forceFill(['blocked_at' => $blocked->blocked_at ?? now()])->save();

        $created = $this->orders($products, $admin, $ivanov, $petrova, $sidorenko);

        $this->report($created);
    }

    /**
     * Свои демо-товары (категория «Демо-товары», SKU DEMO-*): заказы не трогают остатки
     * реального каталога. Остаток пополняется до 100 при каждом запуске.
     */
    private function products()
    {
        $category = Category::firstOrCreate(['slug' => self::CATEGORY_SLUG], ['name' => 'Демо-товары']);

        $items = [
            ['DEMO-1', 'Смеситель для кухни (демо)', 'Grohe', 189.90],
            ['DEMO-2', 'Душевая система (демо)', 'Hansgrohe', 649.00],
            ['DEMO-3', 'Инсталляция для унитаза (демо)', 'Geberit', 412.50],
        ];

        return collect($items)->map(fn ($item) => Product::updateOrCreate(['sku' => $item[0]], [
            'name'         => $item[1],
            'brand'        => $item[2],
            'price'        => $item[3],
            'vat_rate'     => 20,
            'stock'        => 100,
            'category_id'  => $category->id,
            'description'  => 'Демо-товар тестового стенда',
            'is_active'    => true,
            'is_published' => true,
        ]));
    }

    private function organization(string $name, string $unp, string $email, array $addresses): Organization
    {
        $organization = Organization::updateOrCreate(['unp' => $unp], [
            'name'       => $name,
            'legal_name' => $name,
            'address'    => $addresses[0][1],
            'phone'      => $addresses[0][3],
            'email'      => $email,
            'comment'    => 'Демо-данные тестового стенда',
        ]);

        foreach ($addresses as [$title, $address, $contact, $phone, $default]) {
            DeliveryAddress::updateOrCreate(
                ['organization_id' => $organization->id, 'title' => $title],
                ['address' => $address, 'contact_name' => $contact, 'contact_phone' => $phone, 'is_default' => $default]
            );
        }

        return $organization;
    }

    /**
     * Учётка с новым паролем при каждом запуске — пароль попадает в отчёт.
     */
    private function user(string $email, string $name, UserRole $role, ?Organization $organization = null): User
    {
        $password = Str::password(12, symbols: false);

        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name'            => $name,
            'phone'           => $organization?->phone,
            'password'        => $password,
            'organization_id' => $organization?->id,
        ]);
        $user->role = $role;
        $user->email_verified_at ??= now();
        $user->save();

        $this->accounts[] = [$role === UserRole::Admin ? 'Администратор' : 'Клиент', $email, $name, $password];

        return $user;
    }

    /**
     * Заказы во всех статусах и один с запросом на отмену. Уже созданные (по метке
     * в комментарии) не повторяются.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function orders($products, User $admin, User $ivanov, User $petrova, User $sidorenko): array
    {
        if (Order::where('comment', 'like', self::MARK . '%')->exists()) {
            $this->command?->warn('Демо-заказы уже есть — новые не создаются.');

            return [];
        }

        $statuses = app(OrderStatusService::class);
        $path = [OrderStatus::Confirmed, OrderStatus::SentTo1c, OrderStatus::InProgress, OrderStatus::Shipped, OrderStatus::Completed];

        // [клиент, статус, позиции [индекс товара => количество], пояснение]
        $plan = [
            [$ivanov, OrderStatus::New, [0 => 2, 1 => 1], 'новый'],
            [$petrova, OrderStatus::Confirmed, [2 => 1], 'подтверждён менеджером'],
            [$ivanov, OrderStatus::SentTo1c, [1 => 3], 'передан в 1С'],
            [$sidorenko, OrderStatus::InProgress, [0 => 1, 2 => 2], 'в работе'],
            [$petrova, OrderStatus::Shipped, [1 => 1], 'отгружен'],
            [$sidorenko, OrderStatus::Completed, [2 => 1, 0 => 1], 'выполнен'],
            [$ivanov, OrderStatus::Cancelled, [0 => 1], 'отменён менеджером'],
            [$sidorenko, OrderStatus::New, [1 => 1], 'с запросом клиента на отмену'],
        ];

        $created = [];

        foreach ($plan as [$client, $target, $lines, $note]) {
            $order = $this->place($client, $products, $lines, self::MARK . " Заказ {$note}");

            if ($target === OrderStatus::Cancelled) {
                $statuses->change($order, OrderStatus::Cancelled, $admin, 'Демо: клиент передумал');
            } else {
                foreach ($path as $step) {
                    if ($order->status === $target) {
                        break;
                    }

                    $order = $statuses->change($order, $step, $admin);
                }
            }

            if ($note === 'с запросом клиента на отмену') {
                $statuses->requestCancel($order, $client, 'Демо: ошиблись в количестве, просим отменить');
            }

            $order->refresh();
            $created[] = [$order->number, $order->status->label(), $client->email];
        }

        return $created;
    }

    private function place(User $client, $products, array $lines, string $comment): Order
    {
        $cart = Cart::firstOrCreate(['user_id' => $client->id]);
        $cart->items()->delete();

        foreach ($lines as $index => $qty) {
            $product = $products[$index % $products->count()];

            CartItem::create([
                'cart_id'      => $cart->id,
                'product_id'   => $product->id,
                'product_name' => $product->display_name ?: $product->name,
                'quantity'     => $qty,
                'price_at_add' => $product->price,
            ]);
        }

        $address = DeliveryAddress::where('organization_id', $client->organization_id)
            ->orderByDesc('is_default')->value('id');

        return app(CheckoutService::class)->place($client, (string) Str::uuid(), $address, $comment);
    }

    private function report(array $orders): void
    {
        if (! $this->command) {
            return;
        }

        $this->command->info('Демо-учётки (пароли действуют до следующего запуска DemoSeeder):');
        $this->command->table(['Роль', 'Email', 'Имя', 'Пароль'], $this->accounts);
        $this->command->line('blocked@demo.test заблокирован — вход отклоняется.');

        if ($orders) {
            $this->command->info('Демо-заказы:');
            $this->command->table(['Номер', 'Статус', 'Клиент'], $orders);
        }
    }
}
