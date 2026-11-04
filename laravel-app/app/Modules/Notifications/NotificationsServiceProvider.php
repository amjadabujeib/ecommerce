<?php

namespace App\Modules\Notifications;

use App\Modules\ModuleServiceProvider;
use App\Modules\Notifications\Listeners\SendOrderPlacedNotifications;
use App\Modules\Notifications\Listeners\SendOrderStatusNotification;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Events\OrderStatusChanged;
use Illuminate\Support\Facades\Event;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        // The Orders module only announces what happened; this module
        // decides who gets told about it.
        Event::listen(OrderPlaced::class, SendOrderPlacedNotifications::class);
        Event::listen(OrderStatusChanged::class, SendOrderStatusNotification::class);

        $this->loadApiRoutes(__DIR__.'/routes.php');
    }
}
