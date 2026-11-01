<?php

return [
    // Names and JSON records match native/src/services/local, which uses AsyncStorage.
    'collections' => ['profile', 'preferences', 'goals', 'goalVersions', 'mealTypes', 'waterContainers', 'providers', 'foods', 'variants', 'entries', 'loggedMeals', 'favorites', 'meals', 'exercises', 'activities', 'workouts', 'presets', 'water', 'measurements', 'measurementCategories', 'customMeasurements', 'healthRecords', 'sleep', 'nutrientDisplay', 'progressPhotos', 'workoutPhotos', 'watchMeasurementReceipts', 'markaiReceipts', 'mealPlans', 'mealPlanTemplates', 'personalSetup', 'groceryLists',
        'medications', 'medicationSchedules', 'medicationEntries', 'cycleSettings', 'cycleLogs', 'cycles', 'cycleTests', 'symptomEntries', 'pregnancies', 'pregnancyChecklist', 'pregnancyPhotos'],
    'apple_audiences' => array_filter(explode(',', env('APPLE_CLIENT_IDS', 'fitness.qla.dev'))),
    // Google OAuth clients whose ID tokens are accepted. Public identifiers,
    // kept here rather than in .env, like the app keeps them in app.json.
    'google_audiences' => [
        // Web client: the audience of Android tokens, and of iOS tokens when
        // the app passes it as webClientId.
        '669419315944-plvujca4af4jo4a5nso0ckt2lfe7m38m.apps.googleusercontent.com',
        // iOS client.
        '669419315944-0nip6j1itvt66ekoqun0jnnh3h221edm.apps.googleusercontent.com',
    ],
    'registration_coins' => 30,
    // Plain MarkAI replies each user gets free per (UTC) day before a reply
    // spends a coin. Priced tasks and meal plans always cost coins.
    'markai_free_daily' => 5,
    // A week of meals is one large generation, priced above a chat reply.
    'meal_plan_coins' => 10,
    // A full macro plan (calories, protein, carbs, fat) from MarkAI.
    'markai_task_coins' => ['all_macros' => 10],
    // Croatian shelf prices for meal plans; without a key plans are estimated.
    'cijene' => [
        'key' => env('CIJENE_API_KEY'),
    ],
    'markai' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('MARKAI_MODEL', 'google/gemini-2.5-flash'),
        'url' => 'https://openrouter.ai/api/v1/chat/completions',
    ],
];
