<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Информационные страницы: заголовок, адрес (slug), текст в редакторе (Trix), SEO, публикация.
 * Страницы главного меню (Page::MENU — доставка, о компании, контакты) удалить нельзя
 * и адрес у них не меняется: на них ведут меню и подвал.
 */
class AdminPageController extends Controller
{
    /** Вкладки: страницы, услуги, статьи (?type=service|article) */
    public function index()
    {
        $type = array_key_exists((string) request('type'), Page::TYPES) ? (string) request('type') : Page::PAGE;

        $query = Page::query()->where('type', $type);
        $type === Page::ARTICLE ? $query->orderByDesc('published_at')->orderByDesc('id') : $query->orderBy('title');

        $counts = Page::query()->selectRaw('type, COUNT(*) as n')->groupBy('type')->pluck('n', 'type');

        return view('admin.pages', ['pages' => $query->paginate(50)->withQueryString(), 'type' => $type, 'counts' => $counts]);
    }

    public function create()
    {
        $type = array_key_exists((string) request('type'), Page::TYPES) ? (string) request('type') : Page::PAGE;

        return view('admin.page_form', ['page' => new Page(['is_published' => false, 'type' => $type, 'published_at' => $type === Page::ARTICLE ? now() : null])]);
    }

    public function store(): RedirectResponse
    {
        $page = Page::create($this->validated(null));

        return redirect("/admin/page/{$page->id}")->with('success', 'Страница создана');
    }

    public function edit($id)
    {
        return view('admin.page_form', ['page' => Page::findOrFail($id)]);
    }

    public function update($id): RedirectResponse
    {
        $page = Page::findOrFail($id);

        $page->update($this->validated($page));

        return redirect("/admin/page/{$page->id}")->with('success', 'Страница сохранена');
    }

    public function destroy($id): RedirectResponse
    {
        $page = Page::findOrFail($id);

        if (isset(Page::MENU[$page->slug])) {
            return back()->with('error', 'Страницу главного меню удалить нельзя — снимите её с публикации');
        }

        $page->delete();

        return redirect('/admin/pages')->with('success', 'Страница удалена');
    }

    private function validated(?Page $page): array
    {
        $menu = $page && isset(Page::MENU[$page->slug]);

        if (! $menu) {
            // Пустой адрес — из заголовка
            request()->merge(['slug' => Str::slug((string) (request('slug') ?: request('title')))]);
        }

        $data = request()->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => $menu ? ['prohibited'] : ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('pages', 'slug')->ignore($page?->id)],
            'body' => ['nullable', 'string', 'max:200000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'is_published' => ['boolean'],
            'type' => $menu ? ['prohibited'] : ['nullable', 'in:'.implode(',', array_keys(Page::TYPES))],
            'published_at' => ['nullable', 'date'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'string', 'max:500', 'regex:#^(https://|/)#'],
        ], [
            'title.required' => 'Укажите заголовок',
            'slug.required' => 'Укажите адрес страницы',
            'slug.regex' => 'Адрес — латиница, цифры и дефис',
            'slug.unique' => 'Страница с таким адресом уже есть',
            'seo_description.max' => 'Описание для поисковиков — не длиннее 500 символов',
            'image.regex' => 'Обложка — адрес, начинающийся с https:// или /',
        ]);

        $data['is_published'] = request()->boolean('is_published');

        if (! $menu) {
            $data['type'] = ($data['type'] ?? null) ?: ($page?->type ?? Page::PAGE);
        }

        // Статья: дата публикации — по умолчанию сейчас
        if (($data['type'] ?? $page?->type) === Page::ARTICLE && blank($data['published_at'] ?? null) && $data['is_published']) {
            $data['published_at'] = $page?->published_at ?? now();
        }

        return $data;
    }
}
