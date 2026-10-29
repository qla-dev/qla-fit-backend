<?php

/*
 * In-app purchases through RevenueCat. The app buys a package; the server
 * asks RevenueCat's REST API what the account actually bought and grants
 * each store transaction exactly once (revenuecat_purchases). Nothing the
 * app sends decides what is granted.
 *
 * Coin packages are consumables, granted once per store transaction.
 * Programs are sold through three monthly subscription levels: RevenueCat
 * entitlement `trainingN` is granted by level N and every level above it,
 * and a level unlocks every program at that level and below for as long as
 * it runs. Keep the identifiers in step with
 * native/src/services/purchases/products.ts.
 */
return [
    // RevenueCat project secret key (sk_…), read on the server only.
    'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
    // app_user_id the app logs in to RevenueCat with, before the account id.
    'app_user_prefix' => 'qla-fit-user-',

    // product id => AI coins it adds.
    'coin_products' => [
        'fitness.qla.dev.coins100' => 100,
        'fitness.qla.dev.coins200' => 200,
        // No longer sold; kept so an earlier purchase still verifies.
        'fitness.qla.dev.coins50' => 50,
        'fitness.qla.dev.coins500' => 500,
    ],

    // The monthly training subscriptions: product id => level (EUR 5.99,
    // 9.99, 13.99).
    'training_products' => [
        'fitness.qla.dev.training1monthly' => 1,
        'fitness.qla.dev.training2monthly' => 2,
        'fitness.qla.dev.training3monthly' => 3,
    ],

    // Every paid program and the level that unlocks it. Leveled by length:
    // up to 6 weeks, 8–10 weeks, 12 weeks. Mirrors priceTier in
    // native/src/constants/exercisePrograms.ts (a native test compares them).
    'programs' => [
        'shred-30' => 1,
        'morning-mobility-reset' => 1,
        'core-of-steel' => 1,
        'arm-day-every-day' => 1,
        'desk-job-recovery' => 1,
        'kettlebell-forge' => 1,
        'glutes-for-days' => 2,
        'shoulder-boulder' => 2,
        'bulletproof-back' => 2,
        'iron-chest' => 2,
        'legs-that-never-quit' => 2,
        'deadlift-domination' => 2,
        'first-pull-up' => 2,
        'couch-to-confident' => 2,
        'hourglass-sculpt' => 2,
        'athletes-engine' => 2,
        'home-gym-hero' => 2,
        'lean-machine' => 3,
        'powerhouse-5x5' => 3,
        'summer-six-pack' => 3,
        'strong-after-forty' => 3,
    ],
];
