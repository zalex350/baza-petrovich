<?php

namespace App\Http\Controllers;

use App\Models\Page;

/**
 * Информационные страницы: /delivery, /about, /contacts (Page::MENU) и /page/{slug}.
 * Неопубликованная — 404 (администратору видна с пометкой — проверить перед публикацией).
 */
class PageController extends Controller
{
    public function show(string $slug)
    {
        $page = Page::where('slug', $slug)->firstOrFail();

        if (! $page->is_published && ! auth()->user()?->isAdmin()) {
            abort(404);
        }

        // Страница меню открывается по своему короткому адресу
        if (isset(Page::MENU[$slug]) && request()->is('page/*')) {
            return redirect(Page::MENU[$slug], 301);
        }

        return view('pages.show', compact('page'));
    }
}
