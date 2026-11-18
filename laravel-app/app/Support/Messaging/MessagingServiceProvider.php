<?php

namespace App\Support\Messaging;

use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MessageBroker::class, RabbitBroker::class);
    }
}
