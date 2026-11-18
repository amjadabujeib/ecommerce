<?php

namespace App\Console\Commands;

use App\Support\Messaging\RabbitBroker;
use Illuminate\Console\Command;
use Throwable;

final class SetupMessaging extends Command
{
    protected $signature = 'messaging:setup';

    protected $description = 'Declare the durable order queues, bindings, retries and DLQs';

    public function handle(RabbitBroker $broker): int
    {
        if (! config('messaging.enabled')) {
            $this->error('Messaging is disabled.');

            return self::FAILURE;
        }
        try {
            $broker->setup();
            $this->info('Order messaging topology declared.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Topology setup failed: '.get_class($error));

            return self::FAILURE;
        } finally {
            $broker->close();
        }
    }
}
