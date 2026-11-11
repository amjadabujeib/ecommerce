<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Notifications\NewOrderReceived;
use App\Modules\Notifications\Notifications\OrderPlaced;
use App\Modules\Orders\PublicApi\Events\OrderPlaced as OrderPlacedEvent;
use App\Modules\Users\PublicApi\UserDirectory;

/**
 * Confirms the order to the customer and alerts every administrator.
 */
final class SendOrderPlacedNotifications
{
    public function __construct(private readonly UserDirectory $users) {}

    public function handle(OrderPlacedEvent $event): void
    {
        $order = $event->order;

        $this->users->notify($order->userId, new OrderPlaced($order));
        $this->users->notifyAdministrators(new NewOrderReceived($order));
    }
}
