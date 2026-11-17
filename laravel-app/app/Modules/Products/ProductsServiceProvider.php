<?php

namespace App\Modules\Products;

use App\Modules\ModuleServiceProvider;
use App\Modules\Products\Services\CatalogCache;
use App\Modules\Products\Services\CatalogRedisStore;
use App\Support\Database\WriteCommitted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

final class ProductsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        Cache::extend('catalog-snapshots', fn ($app, $config) => Cache::repository(new CatalogRedisStore(
            $app['redis'], config('cache.prefix'), $config['connection'], config('cache.serializable_classes', false),
        )));

        Event::listen(
            WriteCommitted::class,
            fn () => app(CatalogCache::class)->drain(),
        );

        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
