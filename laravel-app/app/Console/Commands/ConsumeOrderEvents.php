<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\DeliveryLedger;
use App\Modules\Notifications\Services\OrderEventDelivery;
use App\Support\Messaging\EventEnvelope;
use App\Support\Messaging\RabbitBroker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final class ConsumeOrderEvents extends Command
{
    protected $signature = 'messaging:consume {path : mail or realtime} {--once : Wait briefly for one delivery and exit}';

    protected $description = 'Consume order notifications with manual acknowledgements and bounded retries';

    public function handle(RabbitBroker $broker, DeliveryLedger $ledger, OrderEventDelivery $delivery): int
    {
        $path = $this->argument('path');
        if (! config('messaging.enabled') || ! in_array($path, ['mail', 'realtime'], true)) {
            $this->error('Enable messaging and choose mail or realtime.');

            return self::FAILURE;
        }
        $backoff = 1;
        do {
            try {
                $broker->consume($path, function (AMQPMessage $message, int $generation) use ($broker, $ledger, $delivery, $path): void {
                    try {
                        $event = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
                        if (! is_array($event) || $generation < 0) {
                            throw new InvalidArgumentException('Invalid message envelope.');
                        }
                        EventEnvelope::validate($event);
                    } catch (JsonException|InvalidArgumentException $error) {
                        $broker->publishToQueue(['quarantined_body_base64' => base64_encode($message->getBody())], RabbitBroker::queue($path).'.dlq', 0, ['failure_type' => get_class($error)]);
                        $message->ack();
                        Log::warning('Malformed order event quarantined.', ['path' => $path, 'error_type' => get_class($error)]);

                        return;
                    }
                    $plan = $ledger->deliver($event, $path, $generation, fn () => $delivery->{$path}($event));
                    if ($plan['action'] !== 'ack') {
                        $destination = RabbitBroker::queue($path).($plan['action'] === 'dlq' ? '.dlq' : '.retry.'.$plan['delay']);
                        $broker->publishToQueue($event, $destination, $plan['generation'], ['failure_type' => $plan['error_type'], 'failure_history' => json_encode($plan['failure_history'], JSON_THROW_ON_ERROR)]);
                        $ledger->transferred($event, $path, $generation, $plan);
                        Log::warning('Order delivery transferred.', ['event_id' => $event['event_id'], 'path' => $path, 'state' => $plan['action'], 'generation' => $plan['generation'], 'error_type' => $plan['error_type']]);
                    }
                    $message->ack();
                }, (bool) $this->option('once'));
                $backoff = 1;
            } catch (Throwable $error) {
                // Socket closure requeues the unacknowledged source on transfer uncertainty.
                $broker->close();
                Log::warning('Notification consumer reconnecting.', ['path' => $path, 'error_type' => get_class($error)]);
                if ($this->option('once')) {
                    $this->error('Consumer failed: '.get_class($error));

                    return self::FAILURE;
                }
                sleep($backoff);
                $backoff = min(8, $backoff * 2);
            } finally {
                $broker->close();
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
