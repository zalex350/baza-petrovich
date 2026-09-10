<div style="
    background:#f5f5f5;
    padding:15px;
    margin-bottom:30px;
    display:flex;
    gap:20px;
    align-items:center;
">

    <a href="/" style="font-weight:bold;">
        Главная
    </a>

    <a href="/catalog/smesiteli">
        Каталог
    </a>

    <form action="{{ route('search.index') }}" method="GET" style="display:flex; gap:8px; align-items:center;">
        <input
            type="text"
            name="q"
            placeholder="Поиск товаров..."
            value="{{ request('q') }}"
            style="padding:6px 12px; border:1px solid #ccc; border-radius:4px; font-size:14px;">
        <button type="submit" style="padding:6px 16px; background:#333; color:white; border:none; border-radius:4px; cursor:pointer;">
            Найти
        </button>
    </form>

    @auth

        <a href="/order">
            Корзина
        </a>

        <a href="/orders">
            Мои заказы
        </a>

        <form method="POST" action="/logout" style="margin-left:auto;">
            @csrf

            <button type="submit">
                Выйти
            </button>
        </form>

    @else

        <a href="/login" style="margin-left:auto;">
            Войти
        </a>

    @endauth

</div>