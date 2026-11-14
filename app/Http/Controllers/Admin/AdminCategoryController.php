<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadException;
use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CatalogMenu;
use App\Services\CategoryIcons;
use App\Services\Images\WebpImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Категории каталога: дерево (порядок — sort_order среди соседей, при равном — по названию),
 * создание с выбором родителя, переименование (slug сохраняется — старые ссылки работают),
 * перемещение (смена родителя, без циклов), вверх / вниз, картинка (webp, плитки подкатегорий),
 * скрыть / показать (is_hidden — нет в меню и каталоге вместе с подкатегориями, товары — в поиске),
 * удаление только пустой (без товаров и подкатегорий).
 * Кэш меню (CatalogMenu) сбрасывает CatalogMenuObserver на каждое сохранение и удаление.
 */
class AdminCategoryController extends Controller
{
    use UploadsProductImages;

    /** Длинная сторона картинки категории, px */
    public const IMAGE_MAX = 600;

    /** Защита от циклов в parent_id (как CatalogMenu) */
    private const MAX_DEPTH = 10;

    public function __construct(private readonly WebpImage $images) {}

    public function index()
    {
        $search = trim((string) request('q'));

        $all = Category::query()->withCount(['products', 'children'])->ordered()->get();

        // Без поиска — дерево (с отступом по глубине); с поиском — найденные, с путём к ним
        $rows = $search === ''
            ? $this->flatten($all->groupBy(fn ($c) => (int) $c->parent_id))
            : $all->filter(fn ($c) => mb_stripos($c->name, $search) !== false)
                ->map(fn ($c) => ['category' => $c, 'depth' => 0])->values();

        $byId = $all->keyBy('id');
        $siblings = $all->groupBy(fn ($c) => (int) $c->parent_id)->map(fn ($g) => $g->pluck('id')->all());

        return view('admin.categories', [
            'rows' => $rows,
            'search' => $search,
            'path' => fn (Category $c) => $this->path($c, $byId),
            'siblings' => $siblings,
        ]);
    }

    /**
     * Превью иконок: категории 1-го и 2-го уровня с иконками (в трёх размерах, на светлом и на цветном фоне)
     * и вся библиотека иконок с числом категорий, где иконка выбрана.
     */
    public function icons(CategoryIcons $icons)
    {
        $all = Category::query()->ordered()->get(['id', 'name', 'parent_id', 'is_hidden', 'icon', 'icon_svg']);
        $byParent = $all->groupBy(fn ($c) => (int) $c->parent_id);

        $rows = collect();
        foreach ($byParent->get(0, collect()) as $root) {
            $rows->push(['category' => $root, 'depth' => 0]);
            foreach ($byParent->get($root->id, collect()) as $child) {
                $rows->push(['category' => $child, 'depth' => 1]);
            }
        }

        return view('admin.category_icons', [
            'rows' => $rows,
            'library' => $icons->library(),
            'used' => $all->whereNotNull('icon')->countBy('icon')->all(),
            'missing' => $rows->filter(fn ($r) => ! $r['category']->is_hidden && ! $icons->render($r['category']->icon, $r['category']->icon_svg))->count(),
        ]);
    }

    public function create()
    {
        $category = new Category(['parent_id' => request()->integer('parent') ?: null]);

        return view('admin.category_form', ['category' => $category, 'parents' => $this->parentOptions()]);
    }

    public function store(): RedirectResponse
    {
        $data = $this->validated();
        $data['slug'] = $this->uniqueSlug(filled($data['slug'] ?? null) ? $data['slug'] : $data['name']);
        $data['sort_order'] = $this->nextOrder($data['parent_id']);

        $category = Category::create($data);

        return redirect('/admin/categories')->with('success', "Категория «{$category->name}» создана");
    }

    public function edit($id)
    {
        $category = Category::withCount(['products', 'children'])->findOrFail($id);

        return view('admin.category_form', ['category' => $category, 'parents' => $this->parentOptions($category)]);
    }

    public function update($id): RedirectResponse
    {
        $category = Category::findOrFail($id);
        $data = $this->validated($category);

        // Перемещение — в конец соседей нового родителя
        if ((int) $data['parent_id'] !== (int) $category->parent_id) {
            $data['sort_order'] = $this->nextOrder($data['parent_id']);
        }

        if (filled($data['slug'] ?? null) && $data['slug'] !== $category->slug) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $category->id);
        } else {
            unset($data['slug']);
        }

        $category->update($data);

        return redirect('/admin/categories')->with('success', "Категория «{$category->name}» сохранена");
    }

    /** Вверх / вниз среди соседей: порядок соседей перенумеровывается 0, 1, 2, … */
    public function move($id): RedirectResponse
    {
        $category = Category::findOrFail($id);
        $direction = request('direction') === 'up' ? -1 : 1;

        DB::transaction(function () use ($category, $direction) {
            $ids = Category::where('parent_id', $category->parent_id)->ordered()->lockForUpdate()->pluck('id')->all();
            $at = array_search($category->id, $ids, true);
            $to = $at + $direction;

            if ($to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

            foreach ($ids as $order => $siblingId) {
                // Через модель — CatalogMenuObserver сбросит кэш меню
                $sibling = Category::find($siblingId);
                if ($sibling->sort_order !== $order) {
                    $sibling->update(['sort_order' => $order]);
                }
            }
        });

        return back()->with('success', "Порядок изменён: «{$category->name}»");
    }

    public function toggleVisibility($id): RedirectResponse
    {
        $category = Category::findOrFail($id);
        $category->update(['is_hidden' => ! $category->is_hidden]);

        return back()->with('success', $category->is_hidden
            ? "Категория «{$category->name}» скрыта — нет в меню и каталоге вместе с подкатегориями"
            : "Категория «{$category->name}» показывается");
    }

    /** Только пустую: без товаров (кроме удалённых) и подкатегорий */
    public function destroy($id): RedirectResponse
    {
        $category = Category::withCount(['products', 'children'])->findOrFail($id);

        if ($category->products_count > 0 || $category->children_count > 0) {
            return back()->with('error', "Нельзя удалить «{$category->name}»: товаров — {$category->products_count}, "
                ."подкатегорий — {$category->children_count}. Сначала перенесите их в другую категорию.");
        }

        $this->images->delete($category->image);
        $category->delete();

        return redirect('/admin/categories')->with('success', "Категория «{$category->name}» удалена");
    }

    public function updateImage($id): RedirectResponse
    {
        $category = Category::findOrFail($id);

        // Ошибка — сообщением с названием категории
        $validator = Validator::make(
            request()->all(),
            ['image' => ['required', ...$this->fileRules()]],
            ['image.required' => 'Выберите файл'] + $this->fileMessages('image'),
            $this->fileNames('image'),
        );

        if ($validator->fails()) {
            return back()->with('error', "«{$category->name}»: ".$validator->errors()->first('image'));
        }

        try {
            $path = $this->images->store(request()->file('image'), 'categories', self::IMAGE_MAX);
        } catch (ImageUploadException $e) {
            return back()->with('error', "«{$category->name}»: ".$e->getMessage());
        }

        $old = $category->image;
        $category->update(['image' => $path]);
        $this->images->delete($old);

        CatalogMenu::flush();

        return back()->with('success', "Картинка категории «{$category->name}» сохранена");
    }

    public function deleteImage($id): RedirectResponse
    {
        $category = Category::findOrFail($id);

        $this->images->delete($category->image);
        $category->update(['image' => null]);

        CatalogMenu::flush();

        return back()->with('success', "Картинка категории «{$category->name}» удалена");
    }

    private function validated(?Category $category = null): array
    {
        // Родитель — не сама категория и не её потомок (иначе цикл)
        $forbidden = $category ? $this->descendantIds($category) : [];

        $data = request()->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id'), Rule::notIn($forbidden)],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'is_hidden' => ['boolean'],
            'icon' => ['nullable', 'string', 'max:100'],
            'icon_file' => ['nullable', 'file', 'max:'.intdiv(CategoryIcons::MAX_UPLOAD, 1024)],
        ], [
            'name.required' => 'Укажите название',
            'parent_id.not_in' => 'Нельзя перенести категорию в саму себя или в её подкатегорию',
            'parent_id.exists' => 'Родительская категория не найдена',
            'slug.regex' => 'Адрес — латиница в нижнем регистре, цифры и дефисы',
            'icon_file.max' => 'SVG больше '.intdiv(CategoryIcons::MAX_UPLOAD, 1024).' КБ',
        ]);

        $data['name'] = trim($data['name']);
        $data['parent_id'] = $data['parent_id'] ?? null;
        $data['is_hidden'] = request()->boolean('is_hidden');

        // Иконку определяет iconData(): загруженный файл важнее выбранной в сетке
        unset($data['icon'], $data['icon_file']);

        return array_merge($data, $this->iconData($category));
    }

    /**
     * Иконка: загруженная SVG (очищенная, CategoryIcons::sanitize) → icon = upload; иначе выбранная
     * из библиотеки; «upload» без нового файла — прежняя загруженная; пусто — без иконки.
     *
     * @return array{icon: ?string, icon_svg: ?string}
     */
    private function iconData(?Category $category): array
    {
        $icons = app(CategoryIcons::class);
        $file = request()->file('icon_file');

        if ($file) {
            $svg = $icons->sanitize((string) file_get_contents($file->getRealPath()));

            if ($svg === null) {
                throw ValidationException::withMessages(['icon_file' => 'Не удалось прочитать SVG: нужен файл SVG с фигурами (path, circle, rect…) и viewBox или размерами']);
            }

            return ['icon' => CategoryIcons::UPLOAD, 'icon_svg' => $svg];
        }

        $icon = trim((string) request('icon'));

        if ($icon === '') {
            return ['icon' => null, 'icon_svg' => null];
        }

        if ($icon === CategoryIcons::UPLOAD && $category?->icon === CategoryIcons::UPLOAD && $category->icon_svg) {
            return ['icon' => CategoryIcons::UPLOAD, 'icon_svg' => $category->icon_svg];
        }

        if (! $icons->exists($icon)) {
            throw ValidationException::withMessages(['icon' => "Нет такой иконки: {$icon}"]);
        }

        return ['icon' => $icon, 'icon_svg' => null];
    }

    /**
     * Дерево по порядку: [category, depth], потомки — сразу за родителем.
     *
     * @return Collection<int, array{category: Category, depth: int}>
     */
    private function flatten(Collection $byParent, int $parentId = 0, int $depth = 0): Collection
    {
        $rows = collect();

        if ($depth >= self::MAX_DEPTH) {
            return $rows;
        }

        foreach ($byParent->get($parentId, []) as $category) {
            $rows->push(['category' => $category, 'depth' => $depth]);
            $rows = $rows->concat($this->flatten($byParent, $category->id, $depth + 1));
        }

        return $rows;
    }

    /** «Сантехника / Смесители» — путь родителей */
    private function path(Category $category, Collection $byId): string
    {
        $names = [];

        for ($id = $category->parent_id, $guard = 0; $id && $guard < self::MAX_DEPTH; $guard++) {
            $parent = $byId->get($id);
            if (! $parent) {
                break;
            }
            array_unshift($names, $parent->name);
            $id = $parent->parent_id;
        }

        return implode(' / ', $names);
    }

    /** Варианты родителя — деревом с отступом; при редактировании — без себя и потомков */
    private function parentOptions(?Category $category = null): array
    {
        $forbidden = $category?->exists ? $this->descendantIds($category) : [];
        $all = Category::query()->ordered()->get(['id', 'name', 'parent_id']);

        return $this->flatten($all->groupBy(fn ($c) => (int) $c->parent_id))
            ->reject(fn ($row) => in_array($row['category']->id, $forbidden, true))
            ->mapWithKeys(fn ($row) => [$row['category']->id => str_repeat('— ', $row['depth']).$row['category']->name])
            ->all();
    }

    /** @return list<int> сама категория и все потомки */
    private function descendantIds(Category $category): array
    {
        return app(CatalogMenu::class)->descendantIds($category);
    }

    private function nextOrder(?int $parentId): int
    {
        return (int) Category::where('parent_id', $parentId)->max('sort_order') + 1;
    }

    /** Slug из названия или введённый; занят — с суффиксом -2, -3… */
    private function uniqueSlug(string $value, ?int $ignore = null): string
    {
        $base = Str::slug($value) ?: 'category';
        $slug = $base;

        for ($i = 2; Category::where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
