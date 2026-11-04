<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Notifications\NewOrderReceived;
use App\Modules\Notifications\Notifications\OrderPlaced;
use App\Modules\Orders\Events\OrderPlaced as OrderPlacedEvent;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Confirms the order to the customer and alerts every administrator.
 */
final class SendOrderPlacedNotifications
{
    public function handle(OrderPlacedEvent $event): void
    {
        $order = $event->order;

        $order->user->notify(new OrderPlaced($order));
        Notification::send(User::query()->admins()->get(), new NewOrderReceived($order));
    }
}
