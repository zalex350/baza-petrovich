<?php

namespace App\Http\Controllers;

use App\Events\LeadCreated;
use App\Models\Lead;
use App\Models\Quiz;
use App\Support\Attribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ответы квиза → заявка (Lead type quiz): ответы — в payload и текстом в комментарии (видно в админке,
 * письме, Telegram, Битрикс24). JSON (quiz.js) или обычная форма.
 */
class QuizController extends Controller
{
    public function submit(int $id)
    {
        $quiz = Quiz::where('is_active', true)->findOrFail($id);

        if (filled(request('website'))) {
            return $this->done($quiz);
        }

        $data = request()->validate([
            'answers' => ['nullable', 'array'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^[\d\s()+\-]{7,}$/'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'page' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => 'Укажите имя',
            'phone.required' => 'Укажите телефон',
            'phone.regex' => 'Телефон — цифры, например +375 29 123-45-67',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных',
        ]);

        $answers = [];
        $missing = [];

        foreach ($quiz->steps as $i => $step) {
            $raw = $data['answers'][$i] ?? null;
            $value = is_array($raw)
                ? implode(', ', array_values(array_intersect(array_map('strval', $raw), $step['options'])))
                : mb_substr(trim((string) $raw), 0, 500);

            if (in_array($step['type'], ['single'], true) && $value !== '' && ! in_array($value, $step['options'], true)) {
                $value = '';
            }

            if ($value === '' && ($step['required'] ?? false)) {
                $missing[] = $step['question'];
            }

            $answers[] = ['question' => $step['question'], 'answer' => $value];
        }

        if ($missing) {
            throw ValidationException::withMessages(['answers' => 'Ответьте на вопрос: '.$missing[0]]);
        }

        $text = collect($answers)->map(fn ($a) => $a['question'].': '.($a['answer'] !== '' ? $a['answer'] : '—'))->implode("\n");
        if (filled($data['comment'] ?? null)) {
            $text .= "\nКомментарий: ".trim($data['comment']);
        }

        DB::transaction(function () use ($quiz, $data, $answers, $text) {
            $lead = Lead::create([
                'type' => 'quiz',
                'quiz_id' => $quiz->id,
                'subject' => $quiz->title,
                'name' => trim($data['name']),
                'phone' => trim($data['phone']),
                'comment' => mb_substr($text, 0, 5000),
                'payload' => ['answers' => $answers, 'page' => $data['page'] ?? null],
                'consent_at' => now(),
                'ip' => request()->ip(),
                'attribution' => Attribution::current(),
            ]);

            LeadCreated::dispatch($lead);
        });

        return $this->done($quiz);
    }

    private function done(Quiz $quiz)
    {
        $message = $quiz->result_text ?: 'Спасибо! Менеджер свяжется с вами и пришлёт расчёт.';

        return request()->expectsJson()
            ? response()->json(['ok' => true, 'title' => $quiz->result_title ?: 'Заявка отправлена', 'message' => $message], 200, [], JSON_UNESCAPED_UNICODE)
            : back()->with('success', $message);
    }
}
