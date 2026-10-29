// Редактор текста информационных страниц (админка «Страницы»): Trix — абзацы, заголовок,
// жирный/курсив, списки, цитата, ссылки. Вложения (файлы, картинки) отключены: HTML страницы
// очищается при выводе (App\Support\Html), а загрузка файлов в редакторе не предусмотрена.
import 'trix';
import 'trix/dist/trix.css';

document.addEventListener('trix-file-accept', (event) => event.preventDefault());
