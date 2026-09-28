<?php

use App\Services\CroatianPrices;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('prices:refresh', function (CroatianPrices $prices) {
    $this->info($prices->refresh()
        ? 'Croatian prices updated from the '.$prices->priceDate().' archive.'
        : 'Croatian prices are already current (or the archive could not be processed).');
})->purpose('Process the latest cijene.dev daily archive into meal-plan prices');

// cijene.dev publishes each day's archive around 21:40. Meal plans also
// refresh it themselves after a response, so this is only a head start on
// servers that run the scheduler.
Schedule::command('prices:refresh')->dailyAt('22:30')->withoutOverlapping();
