# Backend

Production: `https://fit.qla.dev/endpoints` (API: `/endpoints/api`). CORS allows `https://fit.qla.dev`.
Use SQLite and simple Laravel API resource routes/controllers. Preserve native AsyncStorage collection names and record fields.
Run backend tests with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Never reset, seed or wipe a database during deployment. Redeploy updates code/dependencies/caches only; migrations are explicit.
Do not commit `.env`, `.deploy-token`, SQLite files, `vendor`, or generated caches.
