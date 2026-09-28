<nav class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-50">

    <div class="max-w-7xl mx-auto px-6">

        <div class="h-16 flex items-center justify-between">

            <!-- Left -->

            <div class="flex items-center gap-10">

                <a
                    href="/"
                    class="text-2xl font-extrabold tracking-tight text-slate-900"
                >
                    SMESITEL.BY
                </a>

                <div class="hidden lg:flex items-center gap-8 text-sm font-medium">

                    <a
                        href="{{ route('catalog.index') }}"
                        class="{{ request()->is('catalog*') ? 'text-blue-600' : 'text-slate-700 hover:text-blue-600' }}"
                    >
                        Каталог
                    </a>

                    <a
                        href="#brands"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        Бренды
                    </a>

                    <a
                        href="#delivery"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        Доставка
                    </a>

                    <a
                        href="#contacts"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        Контакты
                    </a>

                </div>

            </div>

            <!-- Right -->

            <div class="flex items-center gap-6 text-sm">

                <div class="relative">
                    <form action="{{ route('search.index') }}" method="GET" class="flex items-center gap-2">
                        <input
                            type="text"
                            name="q"
                            value="{{ request('q') }}"
                            placeholder="Поиск..."
                            class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <button
                            type="submit"
                            class="px-4 py-1.5 bg-slate-800 hover:bg-slate-700 text-white rounded-lg text-sm transition">
                            Найти
                        </button>
                    </form>
                    <div id="search-suggestions" class="absolute top-full left-1/2 -translate-x-1/2 mt-1 w-[min(56rem,88vw)] bg-white rounded-xl border border-slate-200 shadow-lg hidden z-50 max-h-96 overflow-y-auto"></div>
                </div>

                @auth

                    <a
                        href="/order"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        🛒 Корзина
                    </a>

                    <a
                        href="/orders"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        Мои заказы
                    </a>

                    <a
                        href="{{ route('profile.edit') }}"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        Профиль
                    </a>

                    @if(auth()->user()->isAdmin())

                        <span class="text-slate-300">
                            |
                        </span>

                        <a
                            href="/admin/products"
                            class="text-slate-500 hover:text-slate-900"
                        >
                            Товары
                        </a>

                        <a
                            href="/admin/orders"
                            class="text-slate-500 hover:text-slate-900"
                        >
                            Заказы
                        </a>

                    @endif

                    <span class="text-slate-300">
                        |
                    </span>

                    <span class="font-medium text-slate-700">
                        {{ auth()->user()->name }}
                    </span>

                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                    >
                        @csrf

                        <button
                            class="text-red-600 hover:text-red-700"
                        >
                            Выйти
                        </button>

                    </form>

                @else

                    <a
                        href="{{ route('login') }}"
                        class="text-slate-700 hover:text-blue-600"
                    >
                        Войти
                    </a>

                @endauth

            </div>

        </div>

    </div>

</nav>