<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PriceSheet;
use App\Support\LocalTime;

/**
 * «Цены» в админке: выгрузка всех товаров в Excel и загрузка изменённого файла (App\Services\PriceSheet).
 */
class AdminPriceController extends Controller
{
    public function index()
    {
        return view('admin.prices');
    }

    public function export(PriceSheet $sheet)
    {
        $path = $sheet->export();

        return response()->download($path, 'prices-'.LocalTime::now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    public function import(PriceSheet $sheet)
    {
        request()->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx'],
        ], [
            'file.required' => 'Выберите файл .xlsx',
            'file.extensions' => 'Нужен файл .xlsx',
            'file.max' => 'Файл больше 10 МБ',
        ]);

        $result = $sheet->import(request()->file('file')->getRealPath());

        return redirect('/admin/prices')
            ->with($result['updated'] || ! $result['errors'] ? 'success' : 'error', "Обновлено товаров: {$result['updated']}, без изменений: {$result['unchanged']}, ошибок: ".count($result['errors']))
            ->with('price_errors', array_slice($result['errors'], 0, 100));
    }
}
