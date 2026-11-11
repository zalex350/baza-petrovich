<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExchangeJobStatus;
use App\Enums\ExchangeJobType;
use App\Http\Controllers\Controller;
use App\Models\ExchangeIp;
use App\Models\ExchangeJob;
use App\Models\ExchangeToken;
use App\Models\Setting;
use App\Support\ExchangeSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Админка «Обмен с 1С» (docs/1C_EXCHANGE_SPEC.md, разделы 2 и 8): журнал заданий
 * (фильтры, ошибки по строкам, исходный файл), токены API (создать, отозвать; токен
 * показывается один раз), белый список IP, настройки порогов и оповещений.
 */
class AdminExchangeController extends Controller
{
    private const TABS = ['jobs', 'tokens', 'ips', 'settings'];

    public function index()
    {
        $tab = in_array(request('tab'), self::TABS, true) ? request('tab') : 'jobs';

        return view('admin.exchange', ['tab' => $tab] + $this->tabData($tab));
    }

    public function job(string $id)
    {
        $job = ExchangeJob::with('token')->findOrFail($id);

        return view('admin.exchange_job', [
            'job' => $job,
            'rowErrors' => $job->errors()->paginate(50),
        ]);
    }

    public function file(string $id)
    {
        $job = ExchangeJob::findOrFail($id);

        abort_unless($job->hasFile(), 404, 'Файл удалён по сроку хранения или не сохранялся');

        $name = $job->type->value.'_'.$job->received_at->format('Y-m-d_His').'.'.pathinfo($job->file_path, PATHINFO_EXTENSION);

        return Storage::disk('exchange')->download($job->file_path, $name);
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

        $jobs = ExchangeJob::with('token')
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->where('received_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'], fn ($q, $to) => $q->where('received_at', '<=', Carbon::parse($to)->endOfDay()))
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
