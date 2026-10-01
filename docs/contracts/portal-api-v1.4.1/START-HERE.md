# SSPOS portal pack — 2026-10-01 (till 0.1.19)

Read first: docs/web-portal-api/ANSWERS-2026-10-01.md (answers to your 1 Oct questions: staff PIN hash format with
samples, PromotionRule tiers and day/time JSON, shop.trading_hours, staff time, newspapers, transfer variance signs,
signer certificate next step).

Back in the spec (missing from the 0.1.15 pack): section 3 X-SSPOS-App-Version 0.1.x, section 17.2 expiresAt never
includes graceDays, section 19.3 baseVersion without echo. New wording: section 10.2 variance signs, section 10.7
pinHash format. docs/web-portal-api/ANSWERS-2026-09-29.md is included this time.

Then, as needed:
- docs/web-portal-api/PORTAL-CHANGES-0.1.15.md - what changed in 0.1.15.
- docs/web-portal-api/UPCOMING-CHANGES.md - changes since, in contract detail (not yet folded into the spec).
- docs/web-portal-api.md - the full contract (section 17 licensing, sections 5-10 sync).
- docs/web-portal-api/licensing/ - licensing schemas, samples and error-codes.json.
- docs/web-portal-api/schemas/, samples/, openapi.yaml, SSPOS.postman_collection.json.
- specs/licensing.md - the till's licensing rules.

X-SSPOS-Contract is still 1. No endpoint, envelope or header changed.
