<?php

use App\Services\ShelfPrices;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('prices:refresh {region? : HR, RS, NL or US; every priced region when left out}', function (?string $region = null) {
    foreach ($region ? [strtoupper($region)] : array_keys(ShelfPrices::REGIONS) as $code) {
        $prices = ShelfPrices::for($code);
        if (! $prices) {
            $this->error("{$code} has no shelf prices.");
        } elseif ($prices->refresh()) {
            $this->info("{$code} prices from ".$prices::SOURCE." updated for {$prices->priceDate()}.");
        } else {
            $this->warn("{$code} prices could not be fetched from ".$prices::SOURCE.' (switched off, no key, or down); the last good basket is kept.');
        }
    }
})->purpose('Fetch the current staple shelf prices (Croatia: cijene.dev, Serbia: data.gov.rs, the Netherlands: Checkjebon, US: Kroger)');

// Chains publish the day's prices overnight. The first meal plan of the day
// fetches them itself (a second or two for Croatia, tens of seconds for the
// Serbian lists), so this saves that wait on servers that run the scheduler.
Schedule::command('prices:refresh')->dailyAt('06:00')->withoutOverlapping();
