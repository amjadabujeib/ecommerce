<?php

namespace App\Modules\Products;

use App\Modules\ModuleServiceProvider;

final class ProductsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
