<?php

namespace Tests\Feature\Exchange;

use App\Enums\ExchangeJobType;
use App\Models\ExchangeJob;
use App\Models\ExchangeJobError;
use App\Models\Product;
use App\Models\User;
use App\Services\Exchange\CatalogHandler;
use App\Services\Exchange\ExchangeJobs;
use App\Services\Exchange\ExchangeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Длины строковых колонок обмена. SQLite в тестах длину VARCHAR не проверяет (и не хранит:
 * колонка объявляется просто varchar), MySQL отвечает «Data too long» — так 500 на стенде
 * при «Применить» для .xlsx (MIME 65 символов в content_type(50)) тесты и пропустили.
 * Здесь: значения обрезаются до ExchangeJob::LENGTHS / ExchangeJobError::LENGTHS на любой БД;
 * на MySQL — ещё и сверка этих констант с реальной схемой. Набор обмена на MySQL — команда
 * в CLAUDE.md («Тесты обмена на MySQL»).
 */
class ColumnLengthsTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('exchange');
    }

    public function test_manual_xlsx_apply_stores_full_mime_within_column_length(): void
    {
        $admin = User::factory()->admin()->create();
        Product::create(['name' => 'A', 'sku' => 'A-1', 'price' => 1, 'stock' => 0])->forceFill(['guid' => 'g-1'])->save();

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['guid', 'sku', 'price', 'stock', 'updated_at']));
        $writer->addRow(Row::fromValues(['g-1', 'A-1', 10, 3, '2026-11-10T18:30:00+03:00']));
        $writer->close();

        $this->actingAs($admin)->post('/admin/exchange/prices', ['file' => new UploadedFile($path, 'prices.xlsx', self::XLSX_MIME, null, true), 'mode' => 'delta']);
        $this->actingAs($admin)->post('/admin/exchange/prices/apply')->assertRedirect()->assertSessionHasNoErrors();

        $job = ExchangeJob::sole();
        $this->assertSame(self::XLSX_MIME, $job->content_type);
        $this->assertSame(65, strlen(self::XLSX_MIME));
        $this->assertLessThanOrEqual(ExchangeJob::LENGTHS['content_type'], mb_strlen($job->content_type));

        foreach (ExchangeJob::LENGTHS as $column => $length) {
            $this->assertLessThanOrEqual($length, mb_strlen((string) $job->getRawOriginal($column)), $column);
        }
    }

    public function test_any_mime_and_long_values_are_cut_to_column_length(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "guid;sku\n");

        $job = app(ExchangeJobs::class)->accept(
            ['path' => $path, 'size' => 9, 'content_type' => 'application/'.str_repeat('x', 400), 'gzip' => false, 'extension' => 'csv'],
            ExchangeJobType::PricesStock, 'delta', null, null, str_repeat('1', 100),
        );

        $raw = DB::table('exchange_jobs')->where('id', $job->id)->first();
        $this->assertSame(255, mb_strlen($raw->content_type));
        $this->assertSame(45, mb_strlen($raw->ip));

        $job->update(['message' => str_repeat('ё', 5000)]);
        $this->assertSame(2000, mb_strlen($job->fresh()->message));
    }

    public function test_row_errors_from_file_are_cut_to_column_length(): void
    {
        $result = new ExchangeResult;
        $result->error(2, str_repeat('g', 300), str_repeat('С', 300), str_repeat('ошибка ', 1000));

        [$error] = $result->errors;
        $this->assertSame([64, 255, 2000], [mb_strlen($error['guid']), mb_strlen($error['sku']), mb_strlen($error['message'])]);
        $this->assertSame(2, $error['line']);
    }

    /** На MySQL: константы длин совпадают с колонками (на SQLite длины в схеме нет) */
    public function test_length_constants_match_mysql_schema(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Длины VARCHAR есть только в схеме MySQL — набор обмена на MySQL: CLAUDE.md');
        }

        foreach (['exchange_jobs' => ExchangeJob::LENGTHS, 'exchange_job_errors' => ExchangeJobError::LENGTHS] as $table => $lengths) {
            $columns = collect(DB::select(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table],
            ))->keyBy('name');

            foreach ($lengths as $column => $length) {
                $info = $columns->get($column);
                $this->assertNotNull($info, "{$table}.{$column}");

                // VARCHAR — ровно длина колонки; TEXT — константа с запасом меньше (байты utf8mb4)
                $info->type === 'varchar'
                    ? $this->assertSame($length, (int) $info->length, "{$table}.{$column}")
                    : $this->assertLessThanOrEqual((int) $info->length, $length * 4, "{$table}.{$column}");
            }
        }

        // Номенклатура: длиннее CatalogHandler::LENGTHS — ошибка строки или обрезка; не длиннее колонки
        // (guid — строже колонки: 64, как в exchange_job_errors)
        $products = collect(DB::select(
            'SELECT COLUMN_NAME AS name, CHARACTER_MAXIMUM_LENGTH AS length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['products'],
        ))->keyBy('name');

        foreach (CatalogHandler::LENGTHS as $column => $length) {
            $this->assertLessThanOrEqual((int) $products->get($column)?->length, $length, "products.{$column}");
        }
    }
}
