<h1>Загрузка фото</h1>

<form method="POST" enctype="multipart/form-data">
    @csrf

    <input type="file" name="images[]" multiple>

    <button type="submit">Загрузить</button>
</form>