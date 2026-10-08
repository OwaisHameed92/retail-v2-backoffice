# Pak POS portal pack - 2026-10-07 (the Pakistan line; no till release yet - the first will be Pak POS 1.0.0)

This pack is for the PAKISTAN portal (https://pak-pos.sspos.co.uk). It is cut from the pak-pos branch of the till.
SSPOS 3 (the UK line) is a separate product with its own packs: nothing in here changes the UK contract.

Read first
1. docs/web-portal-api/ANSWERS-2026-10-07-pakistan.md - our reply to both of your messages of 2026-10-07 (kept beside
   it: PAKISTAN-PORTAL-MESSAGE-2026-10-07.md and PAKISTAN-PORTAL-MESSAGE-2026-10-07-b.md).
2. docs/web-portal-api/UPCOMING-CHANGES.md - every entry marked "pak-pos" at the end of the file. X-SSPOS-Contract is
   still 1; no field, entity or error code was removed or renamed.

What the Pakistan till does differently, in one line each (the detail is in those entries)
- Licence `country` (web-portal-api.md section 17.18): send "PK"; a Pakistan till refuses a key made for another country.
- Signer certificate (17.17): it comes separately from the owner, approved until 2027-12-31. Put it in every token as
  signerCert; no till release is needed. Ask for a new one before the end of 2027.
- The till talks to https://pak-pos.sspos.co.uk and keeps Asia/Karachi time.
- Sales tax is GST (standard 18%, no reduced rate); the shop's STRN and NTN use the existing fields; no field renamed.
- Money is rupees: the sign `Rs`, amounts still plain JSON numbers; cash is rounded to the rupee; the money settings
  start on rupee sums.
- Phone wallets JazzCash, Easypaisa and Raast QR, and the error code sale.wallet_unknown.
- Branch.nation is "Pakistan".
- UK laws and schemes are off: deposit return, lottery, alcohol licensing, minimum unit pricing, HFSS, vape duty, the
  NHS pharmacy module.
- Age: one rule, eighteen (None / Over18 only; no Challenge 25, no generational tobacco rule, no 16 rules).
- The product is named Pak POS; its app versions are their own line from 1.0.0 - never compare them with SSPOS 3's 0.1.x.

Not built yet: FBR POS integration (it needs FBR's documents, a sandbox and a test POS id), a customer's NTN / CNIC,
an Urdu receipt.

Everything else under docs/ is the whole contract as the Pakistan till reads it - schemas, samples, openapi.yaml, the
Postman collection - and specs/licensing.md.
