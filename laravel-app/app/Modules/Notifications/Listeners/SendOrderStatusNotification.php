<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Notifications\OrderStatusChanged;
use App\Modules\Orders\Events\OrderStatusChanged as OrderStatusChangedEvent;

/**
 * Tells the customer that an administrator moved their order to a new status.
 */
final class SendOrderStatusNotification
{
    public function handle(OrderStatusChangedEvent $event): void
    {
        $event->order->user->notify(new OrderStatusChanged($event->order, $event->previousStatus));
    }
}
