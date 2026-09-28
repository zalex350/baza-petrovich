<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Organization;
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
        return view('admin.organization_form', ['organization' => new Organization()]);
    }

    public function store()
    {
        $organization = Organization::create($this->validated());

        return redirect("/admin/organization/{$organization->id}")
            ->with('success', 'Организация создана');
    }

    public function show($id)
    {
        $organization = Organization::with(['users' => fn ($q) => $q->orderBy('name')])
            ->findOrFail($id);

        return view('admin.organization', compact('organization'));
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
            ->with('success', 'Организация сохранена');
    }

    public function destroy($id)
    {
        $organization = Organization::withCount('users')->findOrFail($id);
        $ordersCount  = Order::where('organization_id', $organization->id)->count();

        if ($organization->users_count > 0 || $ordersCount > 0) {
            return back()->with(
                'error',
                "Нельзя удалить организацию «{$organization->name}»: "
                ."пользователей — {$organization->users_count}, заказов — {$ordersCount}."
            );
        }

        $organization->delete();

        return redirect('/admin/organizations')->with('success', 'Организация удалена');
    }

    /**
     * guid не валидируется и не сохраняется: его заполняет только 1С.
     */
    private function validated(?Organization $organization = null): array
    {
        return request()->validate([
            'name'       => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'unp'        => [
                'nullable',
                'digits:9',
                Rule::unique('organizations', 'unp')->ignore($organization?->id),
            ],
            'address'    => ['nullable', 'string', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:50'],
            'email'      => ['nullable', 'email', 'max:255'],
            'comment'    => ['nullable', 'string'],
        ], [
            'unp.digits' => 'УНП должен состоять ровно из 9 цифр.',
            'unp.unique' => 'Организация с таким УНП уже существует.',
        ]);
    }
}
