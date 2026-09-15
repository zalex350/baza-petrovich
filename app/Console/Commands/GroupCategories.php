<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GroupCategories extends Command
{
    protected $signature = 'catalog:group-categories';

    protected $description = 'Create parent categories and assign existing categories to them based on storage/app/imports/category_grouping.json';

    public function handle(): int
    {
        $path = storage_path('app/imports/category_grouping.json');

        if (!file_exists($path)) {
            $this->error('File not found: ' . $path);
            return self::FAILURE;
        }

        $grouping = json_decode(file_get_contents($path), true);

        $notFound = [];
        $assigned = 0;

        foreach ($grouping as $groupName => $categoryNames) {

            $parent = Category::whereNull('parent_id')
                ->where('name', $groupName)
                ->first();

            if (!$parent) {

                $baseSlug = Str::slug($groupName);
                $slug = $baseSlug;
                $i = 2;

                while (Category::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $i;
                    $i++;
                }

                $parent = Category::create([
                    'name' => $groupName,
                    'slug' => $slug,
                ]);
            }

            foreach ($categoryNames as $childName) {

                $child = Category::where('name', $childName)
                    ->whereNull('parent_id')
                    ->where('id', '!=', $parent->id)
                    ->first();

                if (!$child) {
                    $notFound[] = $childName;
                    continue;
                }

                $child->parent_id = $parent->id;
                $child->save();

                $assigned++;
            }
        }

        $this->info("Done. Assigned: {$assigned}. Not found: " . count($notFound));

        if (!empty($notFound)) {
            $this->warn('Not found categories:');
            foreach ($notFound as $name) {
                $this->line(' - ' . $name);
            }
        }

        return self::SUCCESS;
    }
}