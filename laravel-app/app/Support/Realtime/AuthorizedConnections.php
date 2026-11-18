<?php

namespace App\Support\Realtime;

use Laravel\Reverb\Protocols\Pusher\Managers\ArrayChannelConnectionManager;

/** Reverb's normal protocol/topology, with live authorization at its delivery seam. */
final class AuthorizedConnections extends ArrayChannelConnectionManager
{
    public function all(): array
    {
        $allowed = [];
        foreach (parent::all() as $id => $connection) {
            if (app(RealtimeAccess::class)->allows($connection->id(), $this->name)) {
                $allowed[$id] = $connection;
            } else {
                $connection->terminate();
                $this->remove($connection->connection());
            }
        }

        return $allowed;
    }
}
