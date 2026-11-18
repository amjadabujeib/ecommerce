<?php

use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Notifications\NotificationsServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Products\ProductsServiceProvider;
use App\Modules\Users\UsersServiceProvider;
use App\Providers\AppServiceProvider;
use App\Support\Messaging\MessagingServiceProvider;
use App\Support\Realtime\RealtimeServiceProvider;

return [
    AppServiceProvider::class,
    MessagingServiceProvider::class,
    RealtimeServiceProvider::class,
    InventoryServiceProvider::class,
    UsersServiceProvider::class,
    ProductsServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
];
