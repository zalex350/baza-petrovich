<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ProductClientCode;
use App\Rules\Bic;
use App\Rules\Iban;
use App\Rules\Unp;
use Illuminate\Validation\Rule;

/**
 * Администрирование организаций (SRS §10.6).
 */
class AdminOrganizationController extends Controller
{
    public function index()
    {
        $search = trim((string) request('q', ''));

        $organizations = Organization::withCount('users')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                // §10.6.6: поиск по наименованию, УНП, email
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('unp', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.organizations', compact('organizations', 'search'));
    }

    public function create()
    {
        return view('admin.organization_form', ['organization' => new Organization]);
    }

    public function store()
    {
        $organization = Organization::create($this->validated());

        return redirect("/admin/organization/{$organization->id}")
            ->with('success', 'Клиент создан');
    }

    public function show($id)
    {
        $organization = Organization::with(['users' => fn ($q) => $q->orderBy('name')])
            ->withCount('productCodes')
            ->findOrFail($id);

        $tab = request('tab') === 'codes' ? 'codes' : 'main';
        $search = trim((string) request('q', ''));
        $codes = $tab === 'codes' ? $this->codes($organization, $search) : null;

        return view('admin.organization', compact('organization', 'tab', 'search', 'codes'));
    }

    /**
     * Вкладка «Коды товаров»: поиск по нашему артикулу, названию и коду клиента; сортировка — по артикулу.
     */
    private function codes(Organization $organization, string $search)
    {
        return ProductClientCode::query()
            ->select('product_client_codes.*')
            ->with('product:id,sku,article,brand,name,display_name')
            ->leftJoin('products', 'products.id', '=', 'product_client_codes.product_id')
            ->where('product_client_codes.organization_id', $organization->id)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(function ($q) use ($like) {
                    $q->where('product_client_codes.code', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.article', 'like', $like)
                        ->orWhere('products.name', 'like', $like)
                        ->orWhere('products.display_name', 'like', $like);
                });
            })
            ->orderBy('products.sku')
            ->orderBy('product_client_codes.id')
            ->paginate(50)
            ->withQueryString();
    }

    public function edit($id)
    {
        $organization = Organization::findOrFail($id);

        return view('admin.organization_form', compact('organization'));
    }

    public function update($id)
    {
        $organization = Organization::findOrFail($id);

        $organization->update($this->validated($organization));

        return redirect("/admin/organization/{$organization->id}")
            ->with('success', 'Клиент сохранён');
    }

    public function destroy($id)
    {
        $organization = Organization::withCount('users')->findOrFail($id);
        $ordersCount = Order::where('organization_id', $organization->id)->count();

        if ($organization->users_count > 0 || $ordersCount > 0) {
            return back()->with(
                'error',
                "Нельзя удалить клиента «{$organization->name}»: "
                ."пользователей — {$organization->users_count}, заказов — {$ordersCount}."
            );
        }

        $organization->delete();

        return redirect('/admin/organizations')->with('success', 'Клиент удалён');
    }

    /**
     * guid не валидируется и не сохраняется: его заполняет только 1С.
     */
    private function validated(?Organization $organization = null): array
    {
        // Пробелы при вводе допускаются: УНП, BIC и IBAN хранятся без них, BIC и IBAN — заглавными
        request()->merge(array_filter([
            'unp' => filled(request('unp')) ? preg_replace('/\s+/u', '', (string) request('unp')) : null,
            'bic' => filled(request('bic')) ? Bic::normalize(request('bic')) : null,
            'iban' => filled(request('iban')) ? Iban::normalize(request('iban')) : null,
        ], fn ($value) => $value !== null));

        return request()->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            // 9 цифр и контрольная цифра (алгоритм МНС РБ — App\Rules\Unp)
            'unp' => [
                'nullable',
                new Unp,
                Rule::unique('organizations', 'unp')->ignore($organization?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            // Банковские реквизиты — необязательные
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bic' => ['nullable', new Bic],
            'iban' => ['nullable', new Iban],
            'bank_address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'comment' => ['nullable', 'string'],
        ], [
            'unp.unique' => 'Клиент с таким УНП уже существует.',
        ]);
    }
}
