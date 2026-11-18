<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\DeliveryLedger;
use App\Support\Messaging\RabbitBroker;
use Illuminate\Console\Command;
use Throwable;

final class InspectOrderDlq extends Command
{
    protected $signature = 'messaging:dlq {path : mail or realtime} {--replay= : Explicitly replay one terminal event UUID}';

    protected $description = 'Inspect queue and dead-letter counts without consuming messages';

    public function handle(RabbitBroker $broker, DeliveryLedger $ledger): int
    {
        try {
            $path = $this->argument('path');
            if ($eventId = $this->option('replay')) {
                $replayed = $broker->replay($path, $eventId,
                    fn (array $event, int $sourceGeneration): int => $ledger->prepareReplay($event, $path, $sourceGeneration),
                    fn (array $event, int $generation) => $ledger->replayPublished($event, $path, $generation));
                $this->info($replayed ? 'Terminal event replay confirmed.' : 'Event not found in the first 1000 DLQ messages.');

                return $replayed ? self::SUCCESS : self::FAILURE;
            }
            $this->table(['Queue', 'Ready messages', 'Consumers'], $broker->inspect($this->argument('path')));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Queue inspection failed: '.get_class($error));

            return self::FAILURE;
        } finally {
            $broker->close();
        }
    }
}
