<?php

namespace App\Http\Controllers;

use App\Models\Page;

/**
 * Раздел «Услуги»: список услуг (страницы type = service) и страница услуги с кнопкой
 * «Заказать услугу» (заявка, LeadController). Тексты — в админке «Страницы».
 */
class ServiceController extends Controller
{
    public function index()
    {
        $services = Page::services()->published()->get();

        return view('services.index', compact('services'));
    }

    public function show(string $slug)
    {
        $page = Page::services()->where('slug', $slug)->firstOrFail();

        if (! $page->is_published && ! auth()->user()?->isAdmin()) {
            abort(404);
        }

        $others = Page::services()->published()->whereKeyNot($page->id)->get();

        return view('services.show', compact('page', 'others'));
    }
}
