<?php

namespace App\Modules\Orders;

use App\Modules\ModuleServiceProvider;
use App\Modules\Orders\Listeners\ProtectProductHistory;
use App\Modules\Orders\Listeners\RemoveDeletedProductFromCarts;
use App\Modules\Products\PublicApi\Events\ProductDeleting;
use App\Modules\Products\PublicApi\Events\ProductRemoved;
use Illuminate\Support\Facades\Event;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        Event::listen(ProductRemoved::class, RemoveDeletedProductFromCarts::class);
        Event::listen(ProductDeleting::class, ProtectProductHistory::class);

        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
