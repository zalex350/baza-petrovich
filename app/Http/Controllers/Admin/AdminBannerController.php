<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ImageUploadException;
use App\Http\Controllers\Admin\Concerns\UploadsProductImages;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\Images\WebpImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

/**
 * Баннеры слайдера на главной: картинка для десктопа (обязательна) и для телефона, ссылка,
 * порядок, вкл/выкл. Картинки — webp (WebpImage), старые файлы удаляются при замене.
 */
class AdminBannerController extends Controller
{
    use UploadsProductImages;

    /** Длинная сторона, px: десктоп — 1320×400 с запасом на ретину, телефон — 3:2 */
    public const DESKTOP_MAX = 2640;

    public const MOBILE_MAX = 1200;

    public function __construct(private readonly WebpImage $images) {}

    public function index()
    {
        return view('admin.banners', ['banners' => Banner::orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function create()
    {
        return view('admin.banner_form', ['banner' => new Banner(['is_active' => true, 'sort_order' => (int) Banner::max('sort_order') + 1])]);
    }

    public function store(): RedirectResponse
    {
        $data = $this->validated(null);

        try {
            $data['image_desktop'] = $this->upload(request()->file('image_desktop'), 'desktop', self::DESKTOP_MAX);
            $data['image_mobile'] = request()->hasFile('image_mobile')
                ? $this->upload(request()->file('image_mobile'), 'mobile', self::MOBILE_MAX)
                : null;
        } catch (ImageUploadException $e) {
            $this->images->delete($data['image_desktop'] ?? null);

            return back()->withInput()->withErrors(['image_desktop' => $e->getMessage()]);
        }

        Banner::create($data);

        return redirect('/admin/banners')->with('success', 'Баннер добавлен');
    }

    public function edit($id)
    {
        return view('admin.banner_form', ['banner' => Banner::findOrFail($id)]);
    }

    public function update($id): RedirectResponse
    {
        $banner = Banner::findOrFail($id);
        $data = $this->validated($banner);
        $old = [];

        try {
            foreach (['image_desktop' => ['desktop', self::DESKTOP_MAX], 'image_mobile' => ['mobile', self::MOBILE_MAX]] as $field => [$suffix, $max]) {
                if (request()->hasFile($field)) {
                    $data[$field] = $this->upload(request()->file($field), $suffix, $max);
                    $old[] = $banner->{$field};
                }
            }
        } catch (ImageUploadException $e) {
            return back()->withInput()->withErrors(['image_desktop' => $e->getMessage()]);
        }

        if (request()->boolean('remove_mobile') && ! request()->hasFile('image_mobile')) {
            $data['image_mobile'] = null;
            $old[] = $banner->image_mobile;
        }

        $banner->update($data);

        foreach ($old as $path) {
            $this->images->delete($path);
        }

        return redirect('/admin/banners')->with('success', 'Баннер сохранён');
    }

    public function toggle($id): RedirectResponse
    {
        $banner = Banner::findOrFail($id);
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('success', $banner->is_active ? 'Баннер включён' : 'Баннер выключен');
    }

    public function destroy($id): RedirectResponse
    {
        $banner = Banner::findOrFail($id);
        $banner->delete();

        $this->images->delete($banner->image_desktop);
        $this->images->delete($banner->image_mobile);

        return redirect('/admin/banners')->with('success', 'Баннер удалён');
    }

    private function validated(?Banner $banner): array
    {
        $file = $this->fileRules();

        $data = request()->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#i'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
            'image_desktop' => [$banner ? 'nullable' : 'required', ...$file],
            'image_mobile' => ['nullable', ...$file],
        ], [
            'image_desktop.required' => 'Загрузите картинку для десктопа',
            'url.regex' => 'Ссылка — адрес сайта (/catalog/…) или полный адрес (https://…)',
            'sort_order.required' => 'Укажите порядок',
            'sort_order.integer' => 'Порядок — целое число',
        ] + $this->fileMessages('image_desktop') + $this->fileMessages('image_mobile'), [
            'image_desktop' => 'для десктопа',
            'image_mobile' => 'для телефона',
        ]);

        unset($data['image_desktop'], $data['image_mobile']);
        $data['is_active'] = request()->boolean('is_active');

        return $data;
    }

    private function upload(UploadedFile $file, string $suffix, int $max): string
    {
        return $this->images->store($file, 'banners', $max, $suffix);
    }
}
