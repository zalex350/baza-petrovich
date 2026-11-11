<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductClientCode;
use App\Services\ClientCodeImport;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Коды товаров клиентов (product_client_codes): организация называет наш товар своим кодом.
 * Один код организации на товар, код уникален в организации. Правка — только администратор
 * (группа маршрутов role:admin).
 *
 * Карточка товара — блок «Коды клиентов»: добавить, изменить, удалить.
 * Клиент — вкладка «Коды товаров»: список с поиском, удаление, загрузка из Excel / CSV
 * (ClientCodeImport): файл → предпросмотр (строки файла — в сессии) → «Применить».
 */
class AdminClientCodeController extends Controller
{
    private const SESSION = 'client_codes_import';

    public function __construct(private readonly ClientCodeImport $import) {}

    // --- Карточка товара ---

    public function store($id)
    {
        $product = Product::findOrFail($id);

        $data = request()->validate([
            'client_code_organization' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id'),
                Rule::unique('product_client_codes', 'organization_id')->where('product_id', $product->id),
            ],
            'client_code' => ['required', 'string', 'max:100'],
        ], [
            'client_code_organization.required' => 'Выберите клиента.',
            'client_code_organization.exists' => 'Клиент не найден.',
            'client_code_organization.unique' => 'У этого клиента уже есть код этого товара — измените его в таблице.',
            'client_code.required' => 'Введите код клиента.',
            'client_code.max' => 'Код — не длиннее 100 символов.',
        ]);

        $code = trim($data['client_code']);
        $this->ensureCodeFree((int) $data['client_code_organization'], $code, $product->id, 'client_code');

        ProductClientCode::create([
            'product_id' => $product->id,
            'organization_id' => $data['client_code_organization'],
            'code' => $code,
        ]);

        return $this->backToProduct($product)->with('success', 'Код клиента добавлен');
    }

    public function update($id, $code)
    {
        $code = ProductClientCode::where('product_id', $id)->findOrFail($code);
        $field = "client_codes.{$code->id}";

        $value = trim((string) request()->validate([
            $field => ['required', 'string', 'max:100'],
        ], [
            "{$field}.required" => 'Введите код клиента.',
            "{$field}.max" => 'Код — не длиннее 100 символов.',
        ])['client_codes'][$code->id]);

        $this->ensureCodeFree($code->organization_id, $value, $code->product_id, $field);

        $code->update(['code' => $value]);

        return $this->backToProduct($code->product)->with('success', 'Код клиента сохранён');
    }

    public function destroy($id, $code)
    {
        $code = ProductClientCode::where('product_id', $id)->findOrFail($code);
        $code->delete();

        return $this->backToProduct($code->product)->with('success', 'Код клиента удалён');
    }

    // --- Клиент: вкладка «Коды товаров» ---

    public function destroyForOrganization($id, $code)
    {
        $code = ProductClientCode::where('organization_id', $id)->findOrFail($code);
        $code->delete();

        return redirect($this->codesTab($id, (string) request('q')))
            ->with('success', 'Код «'.$code->code.'» удалён');
    }

    public function template()
    {
        return response()->download($this->import->template(), 'client-codes-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Файл → строки в сессии → предпросмотр. База не меняется.
     */
    public function upload($id)
    {
        $organization = Organization::findOrFail($id);

        request()->validate([
            'file' => ['required', 'file', 'max:'.ClientCodeImport::MAX_KB, 'extensions:xlsx,ods,csv,txt'],
            'mode' => ['required', Rule::in(array_keys(ClientCodeImport::MODES))],
        ], [
            'file.required' => 'Выберите файл.',
            'file.max' => 'Файл — не больше '.intdiv(ClientCodeImport::MAX_KB, 1024).' МБ.',
            'file.extensions' => 'Нужен файл Excel (.xlsx), LibreOffice (.ods) или CSV.',
            'file.uploaded' => 'Файл не загрузился — попробуйте ещё раз.',
        ]);

        $file = request()->file('file');

        try {
            $rows = $this->import->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            return redirect($this->codesTab($organization->id))->withErrors(['file' => $e->getMessage()])->withInput();
        }

        session()->put(self::SESSION.'.'.$organization->id, [
            'rows' => $rows,
            'mode' => request('mode'),
            'file' => mb_substr($file->getClientOriginalName(), 0, 255),
        ]);

        return redirect("/admin/organization/{$organization->id}/codes/import");
    }

    public function preview($id)
    {
        $organization = Organization::findOrFail($id);
        $upload = session(self::SESSION.'.'.$organization->id);

        if (! $upload) {
            return redirect($this->codesTab($organization->id))->with('error', 'Загрузите файл с кодами.');
        }

        return view('admin.organization_codes_import', [
            'organization' => $organization,
            'file' => $upload['file'],
            'preview' => $this->import->preview($organization, $upload['rows'], $upload['mode']),
            'modes' => ClientCodeImport::MODES,
        ]);
    }

    public function apply($id)
    {
        $organization = Organization::findOrFail($id);
        $upload = session(self::SESSION.'.'.$organization->id);

        if (! $upload) {
            return redirect($this->codesTab($organization->id))->with('error', 'Загрузите файл с кодами.');
        }

        try {
            $result = $this->import->apply($organization, $upload['rows'], $upload['mode']);
        } catch (RuntimeException $e) {
            return redirect("/admin/organization/{$organization->id}/codes/import")->with('error', $e->getMessage());
        }

        session()->forget(self::SESSION.'.'.$organization->id);

        return redirect($this->codesTab($organization->id))
            ->with('success', 'Коды загружены из файла «'.$upload['file'].'»')
            ->with('codes_report', [
                'file' => $upload['file'],
                'mode' => ClientCodeImport::MODES[$upload['mode']],
                'added' => count($result['add']),
                'updated' => count($result['update']),
                'deleted' => count($result['delete']),
                'unchanged' => $result['unchanged'],
                'errors' => $result['errors'],
            ]);
    }

    public function cancel($id)
    {
        session()->forget(self::SESSION.'.'.$id);

        return redirect($this->codesTab($id));
    }

    /**
     * Код не занят другим товаром этой организации (без учёта регистра — как индекс в MySQL).
     */
    private function ensureCodeFree(int $organizationId, string $code, int $productId, string $field): void
    {
        $taken = ProductClientCode::with('product:id,sku,article,brand,name,display_name')
            ->where('organization_id', $organizationId)
            ->where('product_id', '!=', $productId)
            ->whereRaw('LOWER(code) = ?', [ClientCodeImport::key($code)])
            ->first();

        if ($taken) {
            throw ValidationException::withMessages([
                $field => 'Код «'.$code.'» у этого клиента уже занят товаром '.$this->import->productLabel($taken->product).'.',
            ]);
        }
    }

    private function backToProduct(?Product $product)
    {
        return redirect('/admin/product/'.$product?->id.'#client-codes');
    }

    private function codesTab($organizationId, string $search = ''): string
    {
        return "/admin/organization/{$organizationId}?".http_build_query(array_filter(['tab' => 'codes', 'q' => trim($search)]));
    }
}
