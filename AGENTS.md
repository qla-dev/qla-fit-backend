# Backend

Production: `https://fit.qla.dev/endpoints` (API: `/endpoints/api`). CORS allows `https://fit.qla.dev`.
Use SQLite and simple Laravel API resource routes/controllers. Preserve native AsyncStorage collection names and record fields.
Run backend tests with `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. Never reset, seed or wipe a database during deployment. Redeploy updates code/dependencies/caches only; migrations are explicit.
Do not commit `.env`, `.deploy-token`, SQLite files, `vendor`, or generated caches.

# Deployment

The web repo is the web root; this repo is cloned inside it as `endpoints/`.

After pushing, redeploy by opening (plain-text streamed output):

- Backend: https://fit.qla.dev/endpoints/redeploy.php — `git pull --ff-only`, `composer install --no-dev`, clears caches, `config:cache`
- Web: https://fit.qla.dev/redeploy.php — `git pull --ff-only`, `pnpm install --frozen-lockfile`, `pnpm build`

The backend redeploy does not run migrations. A release that adds a migration needs `php artisan migrate` run explicitly, after the user approves it.
