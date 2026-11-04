<?php

namespace App\Modules\Orders;

use App\Modules\ModuleServiceProvider;
use App\Modules\Orders\Listeners\RemoveDeletedProductFromCarts;
use App\Modules\Products\Events\ProductRemoved;
use Illuminate\Support\Facades\Event;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        Event::listen(ProductRemoved::class, RemoveDeletedProductFromCarts::class);

        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
