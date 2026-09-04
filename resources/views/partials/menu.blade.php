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