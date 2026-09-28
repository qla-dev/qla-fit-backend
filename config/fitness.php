<?php

return [
    // Names and JSON records match native/src/services/local, which uses AsyncStorage.
    'collections' => ['profile', 'preferences', 'goals', 'goalVersions', 'mealTypes', 'waterContainers', 'providers', 'foods', 'variants', 'entries', 'loggedMeals', 'favorites', 'meals', 'exercises', 'activities', 'workouts', 'presets', 'water', 'measurements', 'measurementCategories', 'customMeasurements', 'healthRecords', 'sleep', 'nutrientDisplay', 'progressPhotos', 'workoutPhotos', 'watchMeasurementReceipts', 'markaiReceipts', 'mealPlans'],
    'apple_audiences' => array_filter(explode(',', env('APPLE_CLIENT_IDS', 'fitness.qla.dev'))),
    'registration_coins' => 100,
    // A week of meals is one large generation, priced above a chat reply.
    'meal_plan_coins' => 3,
    'markai' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('MARKAI_MODEL', 'google/gemini-2.5-flash'),
        'url' => 'https://openrouter.ai/api/v1/chat/completions',
    ],
    // Croatian grocery prices. Free plan: non-commercial, daily request cap.
    'cijene' => [
        'key' => env('CIJENE_API_KEY'),
        'url' => env('CIJENE_API_URL', 'https://api.cijene.dev'),
    ],
];
