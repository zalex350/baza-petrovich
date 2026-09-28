<?php

namespace App\View\Composers;

use App\Services\CatalogMenu;
use Illuminate\View\View;

class CatalogMenuComposer
{
    public function __construct(private CatalogMenu $menu)
    {
    }

    public function compose(View $view): void
    {
        $view->with('catalogMenu', $this->menu->tree());
    }
}
