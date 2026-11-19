<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Redirect;
use App\Support\LegacyUrls;
use Illuminate\Http\RedirectResponse;

/**
 * Редиректы 301: свои (старый адрес → новый) и автоматические (смена адреса товара, раздела,
 * страницы — UrlChangeObserver). Адреса старого сайта (каталог, услуги, статьи, работы) редиректы
 * не требуют — их разбирает App\Support\LegacyUrls; «Проверить адрес» показывает, куда он ведёт.
 */
class AdminRedirectController extends Controller
{
    public function index()
    {
        $q = trim((string) request('q'));

        $redirects = Redirect::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('from_path', 'like', '%'.mb_strtolower($q).'%')->orWhere('to_url', 'like', '%'.$q.'%')))
            ->orderByDesc('updated_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.redirects', compact('redirects', 'q'));
    }

    public function store(): RedirectResponse
    {
        $data = request()->validate([
            'from' => ['required', 'string', 'max:500'],
            'to' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)#'],
        ], [
            'from.required' => 'Укажите старый адрес',
            'to.required' => 'Укажите, куда вести',
            'to.regex' => 'Новый адрес — путь от «/» или полный адрес https://…',
        ]);

        $from = Redirect::normalize($data['from']);

        if ($from === '') {
            return back()->withErrors(['from' => 'Главную страницу перенаправить нельзя'])->withInput();
        }

        if (Redirect::normalize($data['to']) === $from && ! str_starts_with($data['to'], 'http')) {
            return back()->withErrors(['to' => 'Адрес ведёт сам на себя'])->withInput();
        }

        Redirect::updateOrCreate(['from_path' => $from], ['to_url' => trim($data['to']), 'status' => 301, 'note' => 'Вручную']);

        return redirect('/admin/redirects')->with('success', 'Редирект сохранён: /'.$from.' → '.trim($data['to']));
    }

    public function destroy($id): RedirectResponse
    {
        Redirect::findOrFail($id)->delete();

        return back()->with('success', 'Редирект удалён');
    }

    /** Куда ведёт адрес (для проверки старых ссылок) */
    public function check(): RedirectResponse
    {
        $url = trim((string) request('url'));
        $target = $url !== '' ? LegacyUrls::resolve($url) : null;

        return redirect('/admin/redirects')->withInput()->with(
            $target ? 'success' : 'error',
            $target ? '/'.Redirect::normalize($url).' → '.$target : 'Для этого адреса правила нет: если страницы на сайте нет — будет 404'
        );
    }
}
