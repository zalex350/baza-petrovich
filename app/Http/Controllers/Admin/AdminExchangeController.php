<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Http\Controllers\Controller;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeToken;
use App\Models\Setting;
use App\Services\Exchange\ManualPricesUpload;
use App\Support\ExchangeSettings;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Админка «Обмен с 1С» (docs/1C_EXCHANGE_SPEC.md, разделы 2 и 8): журнал заданий
 * (фильтры, ошибки по строкам, исходный файл), токены API (создать, отозвать; токен
 * показывается один раз), белый список IP, настройки порогов и оповещений; ручная загрузка
 * цен и остатков (CSV / .xlsx → предпросмотр → «Применить», ManualPricesUpload).
 */
class AdminExchangeController extends Controller
{
    private const TABS = ['jobs', 'upload', 'tokens', 'ips', 'settings'];

    /** Черновик ручной загрузки цен: [token, mode, name] */
    private const UPLOAD_SESSION = 'exchange_prices_upload';

    public function index()
    {
        $tab = in_array(request('tab'), self::TABS, true) ? request('tab') : 'jobs';

        return view('admin.exchange', ['tab' => $tab] + $this->tabData($tab));
    }

    public function job(string $id)
    {
        $job = ExchangeJob::with(['token', 'user'])->findOrFail($id);

        return view('admin.exchange_job', [
            'job' => $job,
            'rowErrors' => $job->errors()->paginate(50),
        ]);
    }

    public function file(string $id)
    {
        $job = ExchangeJob::findOrFail($id);

        abort_unless($job->hasFile(), 404, 'Файл удалён по сроку хранения или не сохранялся');

        $name = $job->type->value.'_'.LocalTime::of($job->received_at)->format('Y-m-d_His').'.'.pathinfo($job->file_path, PATHINFO_EXTENSION);

        return Storage::disk('exchange')->download($job->file_path, $name);
    }

    /** Ручная загрузка цен: файл → черновик → предпросмотр */
    public function uploadPrices(ManualPricesUpload $upload)
    {
        $data = request()->validate([
            'file' => ['required', 'file', 'max:20480', function ($attribute, $value, $fail) {
                if (! in_array(strtolower($value->getClientOriginalExtension()), ['csv', 'txt', 'xlsx'], true)) {
                    $fail('Файл .csv или .xlsx');
                }
            }],
            'mode' => ['required', Rule::in(['delta', 'full'])],
        ], [
            'file.required' => 'Выберите файл',
            'file.max' => 'Файл не больше 20 МБ',
            'file.uploaded' => 'Файл не загрузился — проверьте размер (до 20 МБ)',
        ]);

        $this->discardDraft($upload);

        session()->put(self::UPLOAD_SESSION, [
            'token' => $upload->store($data['file']),
            'mode' => $data['mode'],
            'name' => $data['file']->getClientOriginalName(),
        ]);

        return redirect('/admin/exchange/prices/preview');
    }

    public function previewPrices(ManualPricesUpload $upload)
    {
        [$draft, $path] = $this->draft($upload);

        if (! $path) {
            return redirect('/admin/exchange?tab=upload')->with('error', 'Загрузите файл заново: черновик не найден или устарел');
        }

        return view('admin.exchange_prices_preview', ['draft' => $draft] + $upload->preview($path, $draft['mode']));
    }

    public function applyPrices(ManualPricesUpload $upload)
    {
        [$draft, $path] = $this->draft($upload);

        if (! $path) {
            return redirect('/admin/exchange?tab=upload')->with('error', 'Загрузите файл заново: черновик не найден или уже применён');
        }

        session()->forget(self::UPLOAD_SESSION);
        $job = $upload->apply($path, $draft['mode'], auth()->user(), request()->ip());

        return redirect("/admin/exchange/job/{$job->id}")->with(
            $job->status === ExchangeJobStatus::Done ? 'success' : 'error',
            $job->status === ExchangeJobStatus::Done
                ? "Загрузка применена: принято {$job->rows_ok}, пропущено {$job->rows_skipped}, с ошибкой {$job->rows_error}"
                : 'Загрузка не применена: '.$job->message
        );
    }

    public function cancelPrices(ManualPricesUpload $upload)
    {
        $this->discardDraft($upload);

        return redirect('/admin/exchange?tab=upload')->with('success', 'Загрузка отменена, данные не изменились');
    }

    public function pricesTemplate(ManualPricesUpload $upload)
    {
        return response()->download($upload->template(), 'prices-stock-template.xlsx')->deleteFileAfterSend();
    }

    /** @return array{0: ?array{token: string, mode: string, name: string}, 1: ?string} */
    private function draft(ManualPricesUpload $upload): array
    {
        $draft = session(self::UPLOAD_SESSION);

        return [$draft, is_array($draft) ? $upload->path((string) $draft['token']) : null];
    }

    private function discardDraft(ManualPricesUpload $upload): void
    {
        [, $path] = $this->draft($upload);

        if ($path) {
            $upload->discard($path);
        }

        session()->forget(self::UPLOAD_SESSION);
    }

    public function storeToken()
    {
        $data = request()->validate([
            'name' => ['required', 'string', 'max:100'],
        ], [
            'name.required' => 'Укажите название токена (например, «1С стенд»)',
        ]);

        [, $plain] = ExchangeToken::issue(trim($data['name']), auth()->user());

        // Открытый токен — только в этом ответе (без redirect: не попадает ни в сессию, ни в БД)
        return view('admin.exchange', ['tab' => 'tokens', 'newToken' => $plain] + $this->tabData('tokens'));
    }

    public function revokeToken(int $id)
    {
        $token = ExchangeToken::findOrFail($id);

        if (! $token->isRevoked()) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return redirect('/admin/exchange?tab=tokens')->with('success', "Токен «{$token->name}» отозван");
    }

    public function storeIp()
    {
        $data = request()->validate([
            'ip' => ['required', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! ExchangeIp::isValid(trim($value))) {
                    $fail('Укажите IP-адрес (203.0.113.10) или подсеть (203.0.113.0/24)');
                }
            }, Rule::unique('exchange_ips', 'ip')],
            'comment' => ['nullable', 'string', 'max:255'],
        ], [
            'ip.required' => 'Укажите IP-адрес',
            'ip.unique' => 'Этот адрес уже в списке',
        ]);

        ExchangeIp::create(['ip' => trim($data['ip']), 'comment' => $data['comment'] ?? null, 'created_by' => auth()->id()]);

        return redirect('/admin/exchange?tab=ips')->with('success', "Адрес {$data['ip']} добавлен");
    }

    public function deleteIp(int $id)
    {
        $ip = ExchangeIp::findOrFail($id);
        $ip->delete();

        return redirect('/admin/exchange?tab=ips')->with('success', "Адрес {$ip->ip} удалён");
    }

    public function saveSettings()
    {
        $rules = collect(ExchangeSettings::FIELDS)
            ->map(fn ($f) => ['required', 'integer', "min:{$f[2]}", "max:{$f[3]}"])
            ->all();

        $data = request()->validate($rules + [ExchangeSettings::PENDING_ORDERS_ALERT => ['sometimes', 'boolean']], collect(ExchangeSettings::FIELDS)
            ->flatMap(fn ($f, $key) => [
                "{$key}.required" => "«{$f[0]}» — укажите число",
                "{$key}.integer" => "«{$f[0]}» — целое число",
                "{$key}.min" => "«{$f[0]}» — не меньше {$f[2]}",
                "{$key}.max" => "«{$f[0]}» — не больше {$f[3]}",
            ])->all());

        Setting::put(collect(ExchangeSettings::FIELDS)->map(fn ($f, $key) => (string) (int) $data[$key])->all()
            + [ExchangeSettings::PENDING_ORDERS_ALERT => request()->boolean(ExchangeSettings::PENDING_ORDERS_ALERT) ? '1' : '0']);

        return redirect('/admin/exchange?tab=settings')->with('success', 'Настройки обмена сохранены');
    }

    private function tabData(string $tab): array
    {
        return match ($tab) {
            'jobs' => $this->jobsData(),
            'upload' => [],
            'tokens' => ['tokens' => ExchangeToken::with('creator')->orderByRaw('revoked_at IS NULL DESC')->latest('id')->get()],
            'ips' => ['ips' => ExchangeIp::with('creator')->orderBy('ip')->get(), 'currentIp' => request()->ip()],
            'settings' => ['settings' => collect(ExchangeSettings::FIELDS)->map(fn ($f, $key) => ExchangeSettings::int($key))->all()],
        };
    }

    private function jobsData(): array
    {
        $filters = [
            'type' => ExchangeJobType::tryFrom((string) request('type'))?->value,
            'status' => ExchangeJobStatus::tryFrom((string) request('status'))?->value,
            'from' => $this->date(request('from')),
            'to' => $this->date(request('to')),
        ];

        $jobs = ExchangeJob::with(['token', 'user'])
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            // Даты фильтра — дни по Минску (LocalTime::day), в БД — UTC
            ->when($filters['from'], fn ($q, $from) => $q->where('received_at', '>=', LocalTime::day($from)[0]))
            ->when($filters['to'], fn ($q, $to) => $q->where('received_at', '<=', LocalTime::day($to)[1]))
            ->latest('received_at')
            ->paginate(50)
            ->withQueryString();

        return ['jobs' => $jobs, 'filters' => $filters];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }
}
