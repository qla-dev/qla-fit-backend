<?php

return [
    // Names and JSON records match native/src/services/local, which uses AsyncStorage.
    'collections' => ['profile', 'preferences', 'goals', 'goalVersions', 'mealTypes', 'waterContainers', 'providers', 'foods', 'variants', 'entries', 'loggedMeals', 'favorites', 'meals', 'exercises', 'activities', 'workouts', 'presets', 'water', 'measurements', 'measurementCategories', 'customMeasurements', 'healthRecords', 'sleep', 'nutrientDisplay', 'progressPhotos', 'workoutPhotos', 'watchMeasurementReceipts', 'markaiReceipts'],
    'apple_audiences' => array_filter(explode(',', env('APPLE_CLIENT_IDS', 'fitness.qla.dev'))),
    'registration_coins' => 100,
    'markai' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('MARKAI_MODEL', 'google/gemini-2.5-flash'),
        'url' => 'https://openrouter.ai/api/v1/chat/completions',
    ],
];
