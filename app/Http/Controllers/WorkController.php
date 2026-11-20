<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Work;

/**
 * «Наши работы» (/nashi-raboty: карточки с фильтром по метке) и страница объекта (галерея, описание,
 * отзывы, «Хочу так же» — заявка), «Отзывы» (/otzyvy: тексты и сканы по группам).
 */
class WorkController extends Controller
{
    public function index()
    {
        $tag = trim((string) request('tag')) ?: null;

        $works = Work::published()->ordered()
            ->with('cover')->withCount('images')
            ->when($tag, fn ($q) => $q->where('tag', $tag))
            ->paginate(12)->withQueryString();

        $tags = Work::published()->whereNotNull('tag')->distinct()->orderBy('tag')->pluck('tag');

        return view('works.index', compact('works', 'tags', 'tag'));
    }

    public function show(string $slug)
    {
        $work = Work::where('slug', $slug)->with('images')->firstOrFail();

        if (! $work->is_published && ! auth()->user()?->isAdmin()) {
            abort(404);
        }

        $reviews = $work->reviews()->published()->ordered()->get();
        $more = Work::published()->ordered()->with('cover')->whereKeyNot($work->id)
            ->when($work->tag, fn ($q) => $q->where('tag', $work->tag))->limit(3)->get();

        return view('works.show', compact('work', 'reviews', 'more'));
    }

    public function reviews()
    {
        $reviews = Review::published()->ordered()->with('work')->get();
        $groups = $reviews->groupBy(fn (Review $r) => $r->group ?: 'Отзывы клиентов');

        return view('works.reviews', ['groups' => $groups, 'yandex' => config('shop.reviews_url'), 'google' => config('shop.google_reviews_url')]);
    }
}
