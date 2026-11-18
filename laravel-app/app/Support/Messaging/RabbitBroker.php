<?php

namespace App\Support\Messaging;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;

/** AMQP details stay here rather than leaking into checkout or handlers. */
final class RabbitBroker implements MessageBroker
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    private bool $worker = false;

    public static function queue(string $path): string
    {
        if (! in_array($path, ['mail', 'realtime'], true)) {
            throw new RuntimeException('Unknown notification path.');
        }

        return 'novacart.'.$path.'.v1';
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }
        $this->connection = new AMQPStreamConnection(
            config('messaging.host'), config('messaging.port'), config('messaging.username'),
            config('messaging.password'), config('messaging.vhost'), false, 'AMQPLAIN', null, 'en_US',
            $this->worker ? 2.0 : 0.25, $this->worker ? 65.0 : 0.25, null, false,
            $this->worker ? 30 : 0, $this->worker ? 5.0 : 0.25,
        );
        $this->channel = $this->connection->channel();
        $this->channel->confirm_select();

        return $this->channel;
    }

    public function setup(): void
    {
        $this->worker = true;
        $channel = $this->channel();
        $channel->exchange_declare(config('messaging.exchange'), 'topic', false, true, false);
        foreach (['mail', 'realtime'] as $path) {
            $queue = self::queue($path);
            $quorum = new AMQPTable(['x-queue-type' => 'quorum', 'x-overflow' => 'reject-publish', 'x-delivery-limit' => -1]);
            $channel->queue_declare($queue, false, true, false, false, false, new AMQPTable([
                'x-queue-type' => 'quorum', 'x-overflow' => 'reject-publish',
                'x-dead-letter-strategy' => 'at-least-once', 'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $queue.'.dlq',
            ]));
            $channel->queue_declare($queue.'.dlq', false, true, false, false, false, $quorum);
            $channel->queue_bind($queue, config('messaging.exchange'), 'order.created');
            if ($path === 'realtime') {
                $channel->queue_bind($queue, config('messaging.exchange'), 'order.status_changed');
            }
            foreach (config('messaging.retry_delays') as $delay) {
                $channel->queue_declare($queue.'.retry.'.$delay, false, true, false, false, false, new AMQPTable([
                    'x-queue-type' => 'quorum', 'x-message-ttl' => $delay * 1000,
                    'x-overflow' => 'reject-publish', 'x-dead-letter-strategy' => 'at-least-once',
                    'x-dead-letter-exchange' => '', 'x-dead-letter-routing-key' => $queue,
                ]));
            }
        }
    }

    public function publishEvent(array $event): void
    {
        EventEnvelope::validate($event);
        $deadline = microtime(true) + (float) config('messaging.publish_timeout', 2.0);
        try {
            $channel = $this->channel();
            // Mandatory routing alone cannot detect one missing fan-out destination.
            foreach ($event['type'] === 'order.created' ? ['mail', 'realtime'] : ['realtime'] as $path) {
                if ($deadline - microtime(true) <= 0.25) {
                    throw new RuntimeException('RabbitMQ publication deadline elapsed.');
                }
                $channel->queue_declare(self::queue($path), true);
                if ($deadline - microtime(true) <= 0.25) {
                    throw new RuntimeException('RabbitMQ publication deadline elapsed.');
                }
                $channel->queue_bind(self::queue($path), config('messaging.exchange'), $event['type']);
            }
            $this->publish($event, config('messaging.exchange'), $event['type'], deadline: $deadline);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function publishToQueue(array $event, string $queue, int $generation = 0, array $failure = []): void
    {
        $this->publish($event, '', $queue, $generation, $failure);
    }

    private function publish(array $event, string $exchange, string $key, int $generation = 0, array $failure = [], ?float $deadline = null): void
    {
        $deadline ??= microtime(true) + ($this->worker ? 5.0 : (float) config('messaging.publish_timeout', 2.0));
        try {
            $channel = $this->channel();
            if ($deadline - microtime(true) <= 0.25) {
                throw new RuntimeException('RabbitMQ publication deadline elapsed.');
            }
            $failed = false;
            $channel->set_nack_handler(function () use (&$failed): void {
                $failed = true;
            });
            $channel->set_return_listener(function () use (&$failed): void {
                $failed = true;
            });
            $message = new AMQPMessage(json_encode($event, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $event['event_id'] ?? EventEnvelope::stableId(json_encode($event)),
                'type' => $event['type'] ?? 'invalid',
                'application_headers' => new AMQPTable(['generation' => $generation] + $failure),
            ]);
            if (! $this->worker && $deadline - microtime(true) <= 0.5) {
                throw new RuntimeException('RabbitMQ publication deadline elapsed.');
            }
            $channel->basic_publish($message, $exchange, $key, true);
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('RabbitMQ publication deadline elapsed.');
            }
            $channel->wait_for_pending_acks_returns(min($this->worker ? 5.0 : 0.25, $remaining));
            if ($failed) {
                throw new RuntimeException('RabbitMQ rejected or could not route the publication.');
            }
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function consume(string $path, callable $handler, bool $once = false): void
    {
        $this->worker = true;
        $channel = $this->channel();
        $channel->basic_qos(0, 1, false);
        $processed = false;
        $channel->basic_consume(self::queue($path), '', false, false, false, false,
            function (AMQPMessage $message) use ($handler, &$processed): void {
                $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];
                $handler($message, (int) ($headers['generation'] ?? 0));
                $processed = true;
            });
        while ($channel->is_consuming() && (! $once || ! $processed)) {
            try {
                $channel->wait(null, false, 3);
            } catch (AMQPTimeoutException $error) {
                if ($once) {
                    break;
                }
            }
        }
    }

    public function inspect(string $path): array
    {
        $this->worker = true;
        $result = [];
        foreach ([self::queue($path), self::queue($path).'.dlq'] as $queue) {
            [, $count, $consumers] = $this->channel()->queue_declare($queue, true);
            $result[] = ['queue' => $queue, 'ready' => $count, 'consumers' => $consumers];
        }

        return $result;
    }

    /** Explicitly replay one matching DLQ message; retain source until confirmed. */
    public function replay(string $path, string $eventId, callable $prepare, callable $published): bool
    {
        $this->worker = true;
        $channel = $this->channel();
        $held = [];
        try {
            [, $ready] = $channel->queue_declare(self::queue($path).'.dlq', true);
            for ($i = 0; $i < min(1000, $ready); $i++) {
                $message = $channel->basic_get(self::queue($path).'.dlq', false);
                if ($message === null) {
                    break;
                }
                $held[] = $message;
                $event = json_decode($message->getBody(), true);
                if (! is_array($event) || ($event['event_id'] ?? null) !== $eventId) {
                    continue;
                }
                EventEnvelope::validate($event);
                $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];
                $generation = $prepare($event, (int) ($headers['generation'] ?? 0));
                $this->publishToQueue($event, self::queue($path), $generation, ['operator_replay' => true]);
                $published($event, $generation);
                $message->ack();
                array_pop($held);

                return true;
            }

            return false;
        } finally {
            foreach ($held as $message) {
                if ($channel->is_open()) {
                    $message->nack(false, true);
                }
            }
        }
    }

    public function close(): void
    {
        try {
            // Close the socket directly: outstanding unacknowledged deliveries requeue.
            $this->connection?->getIO()->close();
        } catch (Throwable) {
        } finally {
            $this->channel = null;
            $this->connection = null;
        }
    }
}
