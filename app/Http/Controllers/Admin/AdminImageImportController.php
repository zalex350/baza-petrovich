<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImageImport;
use App\Models\Product;
use App\Services\Images\BulkImport\BulkImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Товары → Загрузить фото» (BulkImages): ZIP или CSV «код;ссылка» → предпросмотр → «Применить» в очередь
 * с прогрессом; журнал загрузок (в том числе с сервера — images:import).
 */
class AdminImageImportController extends Controller
{
    /** Записей журнала на странице */
    private const JOURNAL = 30;

    public function __construct(private readonly BulkImages $bulk) {}

    public function index()
    {
        $this->bulk->pruneDrafts();

        return view('admin.image_import', [
            'imports' => ImageImport::query()->with('user')->latest('id')->limit(self::JOURNAL)
                ->get(['id', 'user_id', 'source', 'name', 'mode', 'status', 'files_total', 'products_total', 'processed', 'added', 'duplicates', 'failed', 'removed', 'message', 'created_at', 'finished_at']),
        ]);
    }

    public function upload(): RedirectResponse
    {
        $source = request()->validate(['source' => ['required', Rule::in([ImageImport::SOURCE_ZIP, ImageImport::SOURCE_CSV])]])['source'];
        $zip = $source === ImageImport::SOURCE_ZIP;

        request()->validate([
            'file' => ['required', 'file', $zip ? 'extensions:zip' : 'extensions:csv,txt', 'max:'.($zip ? BulkImages::ZIP_MAX_KB : BulkImages::CSV_MAX_KB)],
        ], [
            'file.required' => $zip ? 'Выберите ZIP-архив с фото' : 'Выберите CSV-файл',
            'file.uploaded' => 'Файл не загрузился — возможно, он больше '.($zip ? '500' : '5').' МБ',
            'file.extensions' => $zip ? 'Нужен ZIP-архив' : 'Нужен CSV-файл',
            'file.max' => 'Файл больше '.($zip ? '500' : '5').' МБ',
        ]);

        try {
            $import = $this->bulk->draftFromUpload(request()->file('file'), $source, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        if ($import->status === ImageImport::FAILED) {
            $message = $import->message;
            $import->delete();

            return back()->withErrors(['file' => $message])->withInput();
        }

        return redirect("/admin/products/images-import/{$import->id}");
    }

    public function show(int $id)
    {
        $import = ImageImport::with('user')->findOrFail($id);
        $limit = BulkImages::PREVIEW_LIMIT;

        // Найденные — по товарам (первые $limit), неоднозначные — с кандидатами
        $byProduct = collect($import->plan['items'] ?? [])->groupBy('product_id');
        $ids = $byProduct->keys()->take($limit)
            ->merge(collect($import->report['ambiguous'] ?? [])->take($limit)->pluck('products')->flatten());

        return view('admin.image_import_show', [
            'import' => $import,
            'found' => $byProduct->take($limit),
            'products' => Product::query()->whereIn('id', $ids->unique()->all())->get(['id', 'sku', 'article', 'brand', 'name'])->keyBy('id'),
            'limit' => $limit,
        ]);
    }

    public function apply(int $id): RedirectResponse
    {
        $import = ImageImport::findOrFail($id);
        $mode = request()->validate(['mode' => ['required', Rule::in(array_keys(ImageImport::MODES))]], [
            'mode.required' => 'Выберите режим',
        ])['mode'];

        if ($import->files_total === 0) {
            return back()->withErrors(['mode' => 'Нечего загружать: ни один файл не сопоставлен с товаром']);
        }

        try {
            $this->bulk->apply($import, $mode);
        } catch (RuntimeException $e) {
            return back()->withErrors(['mode' => $e->getMessage()]);
        }

        return redirect("/admin/products/images-import/{$import->id}")->with('success', 'Загрузка фото поставлена в очередь');
    }

    public function discard(int $id): RedirectResponse
    {
        $this->bulk->discard(ImageImport::findOrFail($id));

        return redirect('/admin/products/images-import')->with('success', 'Загрузка отменена');
    }

    /** Прогресс для страницы загрузки (опрос) */
    public function status(int $id): JsonResponse
    {
        $import = ImageImport::findOrFail($id);

        return response()->json([
            'status' => $import->status,
            'active' => $import->isActive(),
            'processed' => $import->processed,
            'total' => $import->files_total,
            'percent' => $import->percent(),
            'added' => $import->added,
            'duplicates' => $import->duplicates,
            'failed' => $import->failed,
        ]);
    }

    /** Не найдено и неоднозначно — CSV (Excel: «;», UTF-8 с BOM) */
    public function notFound(int $id): StreamedResponse
    {
        $import = ImageImport::findOrFail($id);
        $report = $import->report ?? [];

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['Файл', 'Причина'], ';', '"', '');
            foreach ($report['not_found'] ?? [] as $row) {
                fputcsv($out, [$row['name'], 'товар не найден'], ';', '"', '');
            }
            foreach ($report['ambiguous'] ?? [] as $row) {
                fputcsv($out, [$row['name'], 'артикул у нескольких товаров — назовите файл БРЕНД_АРТИКУЛ'], ';', '"', '');
            }
            foreach ($report['errors'] ?? [] as $row) {
                fputcsv($out, [$row['name'], $row['message']], ';', '"', '');
            }
            fclose($out);
        }, "photos-not-loaded-{$import->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
