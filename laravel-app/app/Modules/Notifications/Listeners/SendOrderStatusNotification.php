<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Notifications\OrderStatusChanged;
use App\Modules\Orders\PublicApi\Events\OrderStatusChanged as OrderStatusChangedEvent;
use App\Modules\Users\PublicApi\UserDirectory;

/**
 * Tells the customer that an administrator moved their order to a new status.
 */
final class SendOrderStatusNotification
{
    public function __construct(private readonly UserDirectory $users) {}

    public function handle(OrderStatusChangedEvent $event): void
    {
        $this->users->notify($event->order->userId, new OrderStatusChanged($event->order, $event->previousStatus));
    }
}
