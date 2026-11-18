<?php

namespace App\Support\Messaging;

interface MessageBroker
{
    /** Return only after confirmed, routable persistent publication. */
    public function publishEvent(array $event): void;
}
