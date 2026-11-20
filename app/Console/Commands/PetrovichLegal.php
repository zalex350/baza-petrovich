<?php

namespace App\Console\Commands;

use App\Models\Page;
use Illuminate\Console\Command;

/**
 * Юридические страницы (database/data/petrovich/legal/{slug}.html): политика обработки персональных
 * данных, договор-оферта, обмен и возврат — /page/{slug}. Создаются черновиками (на сайте не видны,
 * пока их не проверит юрист и не опубликует администратор); --publish — сразу опубликовать.
 * Уже созданные не перезаписываются без --force. Реквизиты в тексте — метки [[…]] (Page::fillTokens).
 */
class PetrovichLegal extends Command
{
    protected $signature = 'petrovich:legal
        {--publish : опубликовать сразу}
        {--force : перезаписать текст уже созданных страниц}';

    protected $description = 'Юридические страницы: политика ПД, оферта, возврат';

    public function handle(): int
    {
        foreach (Page::LEGAL as $slug => $title) {
            $page = Page::firstOrNew(['slug' => $slug]);

            if ($page->exists && ! $this->option('force')) {
                $this->line("  {$title}: уже есть — не тронута");

                continue;
            }

            $page->fill([
                'title' => $title,
                'body' => trim((string) file_get_contents(database_path("data/petrovich/legal/{$slug}.html"))),
                'seo_description' => $title.' — металлобаза «'.config('seo.site_name').'».',
            ]);
            $page->type = Page::PAGE;
            $page->is_published = $page->exists ? $page->is_published : (bool) $this->option('publish');
            $page->save();

            $this->info("  {$title}: ".($page->is_published ? 'опубликована' : 'черновик').' — '.$page->url());
        }

        if (! $this->option('publish')) {
            $this->warn('Страницы — черновики: проверьте тексты (лучше с юристом), заполните реквизиты в «Настройках» и опубликуйте в админке «Страницы».');
        }

        return self::SUCCESS;
    }
}
