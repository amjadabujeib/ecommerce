<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells administrators that a customer placed a new order.
 */
final class NewOrderReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly Order $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type: string, order_id: int, customer_id: int, total_amount: string, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_order_received',
            'order_id' => $this->order->id,
            'customer_id' => $this->order->user_id,
            'total_amount' => (string) $this->order->total_amount,
            'message' => "New order #{$this->order->id} is waiting to be processed.",
        ];
    }
}
