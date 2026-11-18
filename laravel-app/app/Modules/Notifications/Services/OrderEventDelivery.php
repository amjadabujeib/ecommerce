<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Mail\OrderConfirmation;
use App\Modules\Notifications\Notifications\NewOrderReceived;
use App\Modules\Notifications\Notifications\OrderPlaced;
use App\Modules\Notifications\Notifications\OrderStatusChanged;
use App\Modules\Orders\PublicApi\OrderSummary;
use App\Modules\Users\PublicApi\UserDirectory;
use App\Support\Messaging\EventEnvelope;
use Illuminate\Contracts\Broadcasting\Factory as Broadcasters;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class OrderEventDelivery
{
    public function __construct(private readonly UserDirectory $users, private readonly Broadcasters $broadcasters) {}

    public function mail(array $event): void
    {
        EventEnvelope::validate($event);
        if ($event['type'] !== 'order.created') {
            throw new RuntimeException('The mail path only accepts order creation.');
        }
        $recipient = $this->users->recipient($event['customer_id']);
        if ($recipient === null) {
            throw new RuntimeException('Order recipient is unavailable.');
        }
        $allowed = array_map('strtolower', config('messaging.mail_allowlist'));
        if (config('messaging.require_mail_allowlist') && ! in_array(strtolower($recipient['email']), $allowed, true)) {
            throw new RuntimeException('Recipient is outside the demonstration allowlist.');
        }
        Mail::to($recipient['email'])->send(new OrderConfirmation($event));
    }

    public function realtime(array $event): void
    {
        EventEnvelope::validate($event);
        $data = $event['order'];
        $summary = new OrderSummary($event['order_id'], $event['customer_id'], $data['status'], $data['status_label'], $data['total_amount']);
        if ($event['type'] === 'order.created') {
            $this->notify($event, $event['customer_id'], new OrderPlaced($summary));
            foreach ($this->users->administratorIds() as $id) {
                $this->notify($event, $id, new NewOrderReceived($summary));
            }
            $channels = ['private-admin.orders'];
        } else {
            $this->notify($event, $event['customer_id'], new OrderStatusChanged($summary, $event['previous_status']));
            $channels = ['private-orders.'.$event['order_id']];
        }
        // Browser receives no email address, JWT or provider information.
        $payload = array_intersect_key($event, array_flip(['schema_version', 'event_id', 'type', 'occurred_at', 'order_id', 'order_revision', 'order', 'previous_status']));
        $this->broadcasters->connection('reverb')->broadcast($channels, $event['type'], $payload);
    }

    private function notify(array $event, int $id, Notification $notification): void
    {
        $uuid = EventEnvelope::stableId($event['event_id'].':'.$id.':'.get_class($notification));
        $this->users->notifyOnce($id, $notification, $uuid);
    }
}
