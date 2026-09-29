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
        ? 'Croatian prices updated for '.$prices->priceDate().'.'
        : 'Croatian prices could not be fetched (is CIJENE_API_KEY set?).');
})->purpose('Fetch the current Croatian staple prices from the cijene.dev API');

// Chains publish the day's prices overnight. The first meal plan of the day
// fetches them itself (a second or two), so this only saves that wait on
// servers that run the scheduler.
Schedule::command('prices:refresh')->dailyAt('06:00')->withoutOverlapping();
