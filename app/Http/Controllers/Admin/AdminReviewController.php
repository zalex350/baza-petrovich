<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadException;
use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Work;
use App\Services\Images\WebpImage;
use Illuminate\Http\RedirectResponse;

/**
 * «Отзывы» в админке: текст и/или фото (скан), автор, группа, оценка, дата, объект «Наши работы», публикация.
 */
class AdminReviewController extends Controller
{
    use UploadsProductImages;

    public const IMAGE_MAX = 1600;

    public function __construct(private readonly WebpImage $images) {}

    public function index()
    {
        return view('admin.reviews', ['reviews' => Review::query()->ordered()->with('work')->paginate(50)]);
    }

    public function create()
    {
        return view('admin.review_form', $this->formData(new Review(['is_published' => true, 'rating' => 5])));
    }

    public function store(): RedirectResponse
    {
        $data = $this->validated();

        if (! request()->hasFile('image') && blank($data['text'] ?? null)) {
            return back()->withInput()->withErrors(['text' => 'Нужен текст отзыва или фото']);
        }

        try {
            $data['image'] = request()->hasFile('image') ? $this->images->store(request()->file('image'), 'reviews', self::IMAGE_MAX) : null;
        } catch (ImageUploadException $e) {
            return back()->withInput()->withErrors(['image' => $e->getMessage()]);
        }

        Review::create($data);

        return redirect('/admin/reviews')->with('success', 'Отзыв добавлен');
    }

    public function edit($id)
    {
        return view('admin.review_form', $this->formData(Review::findOrFail($id)));
    }

    public function update($id): RedirectResponse
    {
        $review = Review::findOrFail($id);
        $data = $this->validated();
        $old = null;

        try {
            if (request()->hasFile('image')) {
                $data['image'] = $this->images->store(request()->file('image'), 'reviews', self::IMAGE_MAX);
                $old = $review->image;
            } elseif (request()->boolean('remove_image')) {
                $data['image'] = null;
                $old = $review->image;
            }
        } catch (ImageUploadException $e) {
            return back()->withInput()->withErrors(['image' => $e->getMessage()]);
        }

        $review->update($data);
        $this->images->delete($old);

        return redirect('/admin/reviews')->with('success', 'Отзыв сохранён');
    }

    public function destroy($id): RedirectResponse
    {
        $review = Review::findOrFail($id);
        $review->delete();
        $this->images->delete($review->image);

        return redirect('/admin/reviews')->with('success', 'Отзыв удалён');
    }

    private function formData(Review $review): array
    {
        return [
            'review' => $review,
            'works' => Work::query()->ordered()->pluck('title', 'id'),
            'groups' => Review::query()->whereNotNull('group')->distinct()->orderBy('group')->pluck('group'),
        ];
    }

    private function validated(): array
    {
        $data = request()->validate([
            'author' => ['nullable', 'string', 'max:255'],
            'text' => ['nullable', 'string', 'max:5000'],
            'group' => ['nullable', 'string', 'max:100'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'work_id' => ['nullable', 'integer', 'exists:works,id'],
            'reviewed_on' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer'],
            'image' => ['nullable', ...$this->fileRules()],
        ], $this->fileMessages('image'));

        unset($data['image']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_published'] = request()->boolean('is_published');

        return $data;
    }
}
