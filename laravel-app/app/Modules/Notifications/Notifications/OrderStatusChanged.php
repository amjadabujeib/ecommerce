<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the customer that an administrator moved their order to a new status.
 */
final class OrderStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly OrderStatus $previousStatus,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type: string, order_id: int, previous_status: string, status: string, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_status_changed',
            'order_id' => $this->order->id,
            'previous_status' => $this->previousStatus->value,
            'status' => $this->order->status->value,
            'message' => "Your order #{$this->order->id} is now {$this->order->status->label()}.",
        ];
    }
}
