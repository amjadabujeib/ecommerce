<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneOrderOutbox extends Command
{
    protected $signature = 'messaging:prune {--days=7 : Retain confirmed publications for this many days} {--limit=1000 : Maximum rows removed}';

    protected $description = 'Bounded cleanup of confirmed outbox publications; pending work and delivery deduplication remain';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($days === false || $limit === false) {
            $this->error('Choose days between 1 and 3650 and limit between 1 and 10000.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays($days);
        $ids = DB::table('message_outbox')->useWritePdo()->whereNotNull('published_at')
            ->where('published_at', '<', $cutoff)->orderBy('published_at')->limit($limit)->pluck('event_id');
        $removed = DB::table('message_outbox')->whereIn('event_id', $ids)
            ->whereNotNull('published_at')->where('published_at', '<', $cutoff)->delete();
        $this->info("Removed {$removed} confirmed outbox publications; delivery identities retained.");

        return self::SUCCESS;
    }
}
