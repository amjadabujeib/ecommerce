<?php

use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\ReservationRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:recover-reservations', function (): void {
    $count = app(ReservationRecovery::class)->recover();
    $this->info("Recovered {$count} abandoned checkout reservations.");
})->purpose('Release abandoned checkout reservations older than five minutes');

Schedule::command('orders:recover-reservations')->everyMinute();

Artisan::command('orders:recover-cancellations', function (): void {
    $count = app(OrderService::class)->recoverCancellations();
    $this->info("Recovered {$count} pending cancellations.");
})->purpose('Finish durable order cancellations after inventory or database failures');

Schedule::command('orders:recover-cancellations')->everyMinute();
