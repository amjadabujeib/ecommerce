<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:recover-reservations', function (): void {
    $count = app(\App\Modules\Orders\Services\ReservationRecovery::class)->recover();
    $this->info("Recovered {$count} abandoned checkout reservations.");
})->purpose('Release abandoned checkout reservations older than five minutes');

\Illuminate\Support\Facades\Schedule::command('orders:recover-reservations')->everyMinute();
