<?php

use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Products\ProductsServiceProvider;
use App\Modules\Users\UsersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    InventoryServiceProvider::class,
    UsersServiceProvider::class,
    ProductsServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
];
