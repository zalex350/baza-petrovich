<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadException;
use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Work;
use App\Models\WorkImage;
use App\Services\Images\WebpImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * «Наши работы» в админке: список, объект (название, адрес, метка, текст в редакторе, SEO, публикация),
 * фото — загрузка нескольких сразу, удаление, первое по порядку — обложка.
 */
class AdminWorkController extends Controller
{
    use UploadsProductImages;

    /** Длинная сторона фото, px */
    public const IMAGE_MAX = 1600;

    public function __construct(private readonly WebpImage $images) {}

    public function index()
    {
        $works = Work::query()->ordered()->with('cover')->withCount('images')->paginate(50);

        return view('admin.works', compact('works'));
    }

    public function create()
    {
        return view('admin.work_form', ['work' => new Work(['is_published' => true])]);
    }

    public function store(): RedirectResponse
    {
        $work = Work::create($this->validated(null));
        $errors = $this->uploadPhotos($work);

        return redirect("/admin/work/{$work->id}")->with('success', 'Объект создан')->withErrors($errors);
    }

    public function edit($id)
    {
        return view('admin.work_form', ['work' => Work::with('images')->findOrFail($id)]);
    }

    public function update($id): RedirectResponse
    {
        $work = Work::findOrFail($id);
        $work->update($this->validated($work));
        $errors = $this->uploadPhotos($work);

        return redirect("/admin/work/{$work->id}")->with('success', 'Объект сохранён')->withErrors($errors);
    }

    public function destroyImage($id, $image): RedirectResponse
    {
        $image = WorkImage::where('work_id', $id)->findOrFail($image);
        $image->delete();
        $this->images->delete($image->path);

        return back()->with('success', 'Фото удалено');
    }

    /** Сделать фото обложкой — первым по порядку */
    public function coverImage($id, $image): RedirectResponse
    {
        $image = WorkImage::where('work_id', $id)->findOrFail($image);
        $image->update(['sort_order' => (int) WorkImage::where('work_id', $id)->min('sort_order') - 1]);

        return back()->with('success', 'Обложка изменена');
    }

    public function destroy($id): RedirectResponse
    {
        $work = Work::with('images')->findOrFail($id);

        foreach ($work->images as $image) {
            $this->images->delete($image->path);
        }

        $work->delete();

        return redirect('/admin/works')->with('success', "Объект «{$work->title}» удалён");
    }

    /** @return array<string, string> ошибки загрузки */
    private function uploadPhotos(Work $work): array
    {
        $files = array_filter((array) request()->file('photos', []));
        $failed = [];
        $order = (int) $work->images()->max('sort_order');

        foreach (array_slice($files, 0, 50) as $file) {
            try {
                $path = $this->images->store($file, 'works', self::IMAGE_MAX);
                $work->images()->create(['path' => $path, 'sort_order' => ++$order]);
            } catch (ImageUploadException $e) {
                $failed[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        return $failed ? ['photos' => implode('; ', $failed)] : [];
    }

    private function validated(?Work $work): array
    {
        request()->merge(['slug' => Str::slug((string) (request('slug') ?: request('title')))]);

        $data = request()->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:150', Rule::unique('works', 'slug')->ignore($work?->id)],
            'location' => ['nullable', 'string', 'max:255'],
            'tag' => ['nullable', 'string', 'max:100'],
            'completed_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:200000'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:50'],
            'photos.*' => $this->fileRules(),
        ], [
            'title.required' => 'Укажите название',
            'slug.unique' => 'Такой адрес уже есть — укажите другой',
            'slug.required' => 'Укажите адрес или название латиницей',
        ] + $this->fileMessages('photos.*'));

        unset($data['photos']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_published'] = request()->boolean('is_published');

        return $data;
    }
}
