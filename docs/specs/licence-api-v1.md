# Licence API (module 1.5) — superseded by the EPOS contract v1.3.1

Our own draft is gone. The portal implements `docs/contracts/portal-api-v1.4.1/docs/web-portal-api.md`:
- §17.15.1 `POST /api/v1/licence/activate` and §17.15.2 `POST /api/v1/licence/validate` (per till, no branch key);
- §17.7 `POST /api/v1/devices/deactivate` (per-till release); §17.9 what the till does with each status;
- §17.11 compatibility (headers, `Idempotency-Key`, unknown fields), §17.12 errors and rate limits, §17.2 the token.
Schemas and samples: `docs/web-portal-api/licensing/{schemas,samples}`. Our key layout: `licence-key-format.md`.
Code: `app/Domain/Licensing/Api`, `routes/api.php`; try it with `php artisan licence:simulate`.
Portal choices (status mapping, feature names, grace) are in `docs/DECISIONS.md` ("Licence API v1.3.1").
