# SSPOS portal pack - 2026-10-06 (till 0.1.53)

New since the 0.1.52 pack (same day): our reply to your 4 points is docs/web-portal-api/ANSWERS-2026-10-06-b.md.
Contract change: the last entry of docs/web-portal-api/UPCOMING-CHANGES.md ("devices/deactivate body gains optional
tokenSha256 and installCode"). X-SSPOS-Contract is still 1; nothing removed or renamed.

1. devices/deactivate (till 0.1.53+): optional tokenSha256 (SHA-256 hex of the licence token the till holds, null when
   none) and installCode - licensing/schemas/deactivate-request.schema.json, sample deactivate-request.main-till.json,
   web-portal-api.md section 17.7.
2. Your 403 device.token_mismatch is in licensing/samples/error-codes.json and section 17.7 Errors.

Everything from the 0.1.52 pack is included unchanged (ANSWERS-2026-10-06.md, PORTAL-CHANGES-2026-10-06.md, schemas,
samples, openapi.yaml, Postman, specs/licensing.md).
