<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadException;
use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CatalogMenu;
use App\Services\Images\WebpImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Validator;

/**
 * Категории: картинка (webp, как у фото товаров) — плитки подкатегорий над списком товаров.
 * Дерево, названия и порядок ведутся импортом каталога; здесь — только картинка.
 */
class AdminCategoryController extends Controller
{
    use UploadsProductImages;

    /** Длинная сторона картинки категории, px */
    public const IMAGE_MAX = 600;

    public function __construct(private readonly WebpImage $images) {}

    public function index()
    {
        $search = trim((string) request('q'));

        $categories = Category::query()
            ->with('parent')
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.categories', compact('categories', 'search'));
    }

    public function updateImage($id): RedirectResponse
    {
        $category = Category::findOrFail($id);

        // Форма загрузки — в каждой строке списка: ошибка — сообщением с названием категории,
        // а не у поля (иначе она показалась бы во всех строках)
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
}
