<?php

/*
 * In-app purchases through RevenueCat. The app buys a package; the server
 * asks RevenueCat's REST API what the account actually bought and grants
 * each store transaction exactly once (revenuecat_purchases). Nothing the
 * app sends decides what is granted.
 *
 * Every product is a consumable: coins are spent, and the three program
 * prices are bought again for each program. Keep the identifiers in step
 * with native/src/services/purchases/products.ts.
 */
return [
    // RevenueCat project secret key (sk_…), read on the server only.
    'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
    // app_user_id the app logs in to RevenueCat with, before the account id.
    'app_user_prefix' => 'qla-fit-user-',

    // product id => AI coins it adds.
    'coin_products' => [
        'fitness.qla.dev.coins100' => 100,
        'fitness.qla.dev.coins500' => 500,
    ],

    // The three program prices: product id => USD list price.
    'program_products' => [
        'fitness.qla.dev.program499' => 4.99,
        'fitness.qla.dev.program1399' => 13.99,
        'fitness.qla.dev.program2899' => 28.99,
    ],

    // Every paid program and the price product that unlocks it. Priced by
    // length: up to 6 weeks, 8–10 weeks, 12 weeks. Mirrors priceTier in
    // native/src/constants/exercisePrograms.ts (a native test compares them).
    'programs' => [
        'shred-30' => 'fitness.qla.dev.program499',
        'morning-mobility-reset' => 'fitness.qla.dev.program499',
        'core-of-steel' => 'fitness.qla.dev.program499',
        'arm-day-every-day' => 'fitness.qla.dev.program499',
        'desk-job-recovery' => 'fitness.qla.dev.program499',
        'kettlebell-forge' => 'fitness.qla.dev.program499',
        'glutes-for-days' => 'fitness.qla.dev.program1399',
        'shoulder-boulder' => 'fitness.qla.dev.program1399',
        'bulletproof-back' => 'fitness.qla.dev.program1399',
        'iron-chest' => 'fitness.qla.dev.program1399',
        'legs-that-never-quit' => 'fitness.qla.dev.program1399',
        'deadlift-domination' => 'fitness.qla.dev.program1399',
        'first-pull-up' => 'fitness.qla.dev.program1399',
        'couch-to-confident' => 'fitness.qla.dev.program1399',
        'hourglass-sculpt' => 'fitness.qla.dev.program1399',
        'athletes-engine' => 'fitness.qla.dev.program1399',
        'home-gym-hero' => 'fitness.qla.dev.program1399',
        'lean-machine' => 'fitness.qla.dev.program2899',
        'powerhouse-5x5' => 'fitness.qla.dev.program2899',
        'summer-six-pack' => 'fitness.qla.dev.program2899',
        'strong-after-forty' => 'fitness.qla.dev.program2899',
    ],
];
