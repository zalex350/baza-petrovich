<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteScript;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;

/**
 * «Коды и виджеты»: код стороннего сервиса (квиз MoClients / Marquiz, чат, обратный звонок, счётчики),
 * место вставки (<head> или перед </body>) и страницы.
 */
class AdminSiteScriptController extends Controller
{
    public function index()
    {
        return view('admin.site_scripts', ['scripts' => SiteScript::query()->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function create()
    {
        return view('admin.site_script_form', ['script' => new SiteScript(['is_active' => true, 'position' => 'body', 'targets' => ['all']])]);
    }

    public function store(): RedirectResponse
    {
        SiteScript::create($this->validated());

        return redirect('/admin/site-scripts')->with('success', 'Код добавлен');
    }

    public function edit($id)
    {
        return view('admin.site_script_form', ['script' => SiteScript::findOrFail($id)]);
    }

    public function update($id): RedirectResponse
    {
        SiteScript::findOrFail($id)->update($this->validated());

        return redirect('/admin/site-scripts')->with('success', 'Код сохранён');
    }

    public function destroy($id): RedirectResponse
    {
        SiteScript::findOrFail($id)->delete();

        return redirect('/admin/site-scripts')->with('success', 'Код удалён');
    }

    private function validated(): array
    {
        $data = request()->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100000'],
            'position' => ['required', Rule::in(array_keys(SiteScript::POSITIONS))],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ], [
            'name.required' => 'Укажите название (например, «Квиз MoClients — заборы»)',
            'code.required' => 'Вставьте код сервиса',
            'targets.required' => 'Отметьте, где показывать',
        ]);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = request()->boolean('is_active');

        return $data;
    }
}
