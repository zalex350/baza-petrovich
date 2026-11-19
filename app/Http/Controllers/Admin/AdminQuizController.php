<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Квизы: конструктор шагов, вид (блок / кнопка), где показывать, текст после отправки; ответы — «Заявки с сайта».
 */
class AdminQuizController extends Controller
{
    public function index()
    {
        $quizzes = Quiz::query()->orderBy('sort_order')->orderBy('id')->get();
        $counts = Lead::query()->whereNotNull('quiz_id')->selectRaw('quiz_id, count(*) c')->groupBy('quiz_id')->pluck('c', 'quiz_id');

        return view('admin.quizzes', compact('quizzes', 'counts'));
    }

    public function create()
    {
        return view('admin.quiz_form', ['quiz' => new Quiz(['is_active' => true, 'display' => 'inline', 'steps' => [], 'targets' => []])]);
    }

    public function store(): RedirectResponse
    {
        $quiz = Quiz::create($this->validated());

        return redirect("/admin/quiz/{$quiz->id}")->with('success', 'Квиз создан');
    }

    public function edit($id)
    {
        return view('admin.quiz_form', ['quiz' => Quiz::findOrFail($id)]);
    }

    public function update($id): RedirectResponse
    {
        Quiz::findOrFail($id)->update($this->validated());

        return back()->with('success', 'Квиз сохранён');
    }

    public function destroy($id): RedirectResponse
    {
        $quiz = Quiz::findOrFail($id);
        $quiz->delete();

        return redirect('/admin/quizzes')->with('success', "Квиз «{$quiz->title}» удалён");
    }

    private function validated(): array
    {
        $data = request()->validate([
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:1000'],
            'button_label' => ['nullable', 'string', 'max:100'],
            'display' => ['required', Rule::in(array_keys(Quiz::DISPLAY))],
            'steps' => ['required', 'json'],
            'result_title' => ['nullable', 'string', 'max:255'],
            'result_text' => ['nullable', 'string', 'max:1000'],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['string', 'max:100'],
            'sort_order' => ['nullable', 'integer'],
        ], ['title.required' => 'Укажите название']);

        $data['steps'] = Quiz::normalizeSteps(json_decode($data['steps'], true));

        if ($data['steps'] === []) {
            throw ValidationException::withMessages(['steps' => 'Добавьте хотя бы один вопрос']);
        }

        $data['targets'] = array_values(array_unique($data['targets'] ?? []));
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = request()->boolean('is_active');

        return $data;
    }
}
