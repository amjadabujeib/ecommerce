<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Orders\PublicApi\OrderSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Confirms to the customer that their order was received.
 */
final class OrderPlaced extends Notification
{
    use Queueable;

    public function __construct(private readonly OrderSummary $order) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type: string, order_id: int, status: string, total_amount: string, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_placed',
            'order_id' => $this->order->id,
            'status' => $this->order->status,
            'total_amount' => (string) $this->order->totalAmount,
            'message' => "Your order #{$this->order->id} was placed successfully.",
        ];
    }
}
