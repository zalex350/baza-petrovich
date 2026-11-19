<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Seo;

/**
 * «Статьи» (/stati): советы, обзоры заборов и ворот, примеры объектов — страницы type = article
 * (админка «Страницы»; перенесены со старого сайта командой petrovich:articles).
 */
class ArticleController extends Controller
{
    public const PER_PAGE = 12;

    public function index()
    {
        $articles = Page::articles()->published()->paginate(self::PER_PAGE)->withQueryString();

        seo()->description('Статьи металлобазы «'.Seo::siteName().'»: как выбрать забор, профнастил и металлоштакетник, ворота и автоматику, нормы установки заборов в Беларуси, примеры объектов.');

        return view('articles.index', compact('articles'));
    }

    public function show(string $slug)
    {
        $page = Page::articles()->where('slug', $slug)->firstOrFail();

        if (! $page->is_published && ! auth()->user()?->isAdmin()) {
            abort(404);
        }

        $more = Page::articles()->published()->whereKeyNot($page->id)->limit(3)->get();

        return view('articles.show', compact('page', 'more'));
    }
}
