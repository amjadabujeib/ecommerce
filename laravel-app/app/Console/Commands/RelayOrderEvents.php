<?php

namespace App\Console\Commands;

use App\Support\Messaging\Outbox;
use Illuminate\Console\Command;

final class RelayOrderEvents extends Command
{
    protected $signature = 'messaging:relay {--once : Process one bounded batch and exit}';

    protected $description = 'Publish committed order outbox entries with confirms';

    public function handle(Outbox $outbox): int
    {
        if (! config('messaging.enabled')) {
            $this->error('Messaging is disabled.');

            return self::FAILURE;
        }
        do {
            $count = $outbox->publishPending();
            if ($this->option('once')) {
                $this->info("Published {$count} committed order events.");
                break;
            }
            if ($count === 0) {
                sleep(1);
            }
        } while (true);

        return self::SUCCESS;
    }
}
