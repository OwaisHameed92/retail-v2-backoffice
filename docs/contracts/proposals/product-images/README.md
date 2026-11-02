# Contract proposal: product pictures from the till (`product-images`)

**For:** the SSPOS EPOS till team. **From:** the Switch & Save portal team. **Date:** 2026-10-25, revised 2026-11-02.
**Builds on:** contract v1.4.1 (`docs/contracts/portal-api-v1.4.1`): §3 transport, §9 errors, §17.12 rate limits,
§18.9 "No big blobs in sync", PORTAL-CHANGES-2026-10-06 §7 "Product pictures" (0.1.35).
**Status:** PROPOSAL. Additive only: `X-SSPOS-Contract` stays `1`; no v1.4.1 endpoint, entity, member or code changes.
Three new calls under `sync/`, seven new error codes (`error-codes.additions.json`).
**Portal side:** built and switched off (`TILL_PRODUCT_IMAGES=false`). Until it is switched on every new call answers
`501 image.not_available`, and the portal reads no picture anywhere.

> **Revision 2026-11-02 (IMG-2)**, after the till team's answers (`ANSWERS-2026-11-02.md`; first till version 0.1.83).
> Agreed as proposed: raw bytes, the three calls, the limits, the list call (no `imageHash` in `Product`), 501 as the
> capability check. Added or changed here:
> - **New optional header `X-SSPOS-Image-Source: own | library | found | unknown`** on every upload (§4.1), stored as
>   the picture's origin; the list reply carries it as an added member `origin` (§4.3). The public online store shows
>   `own` and `library` (credited) by default, `found` / `unknown` only when the shop turns on "Show found pictures" (§6).
> - **The till deletes only pictures it sent itself** and then removed: no sweep step 3; the portal never deletes a
>   till picture on its own (§5).
> - **Product row first, picture after:** the till uploads once the `Product` row is acknowledged by push, so
>   `404 product.not_found` is rare; when it comes the till backs off 10 min → 1 h → 6 h → daily (§5).
> - **401 / 403 / 426 on a picture call pause pictures only, for a day;** push and pull never stop over a picture
>   answer (a really wrong key is caught by push as before) (§5).
> - **Speed:** at most one picture call every 2 seconds (30 a minute, half our limit), lists at `max=200`, paused while
>   a sale is open on the main till (§3, §5).
> - **Only the branch's main till uploads** (or a single till); in a business with several shops the shop whose main
>   till holds the file sends it (§3).
> - **The till sends JPEG or PNG only** (never WebP); a file over 2 MB / 2000 px is sent as a copy ≤ 1200 px (JPEG 85)
>   and the hash of the copy is remembered; nothing under 16 px is sent (§4.1, §5).
> - **Q10:** portal → till pictures later as optional hub-owned `Product.imageUrl` + `imageHash` (§8).

## 1. Why

Since 0.1.35 the till finds and saves a picture for each product and pushes `Product.imagePath`. That is a path on
the main till's PC (`C:\ProgramData\SSPOS\product-images\01M4….jpg`): the portal can never open it. So the online
store, the portal's Products pages and anything else in the cloud show a placeholder for products the shop already
has a good picture of. §18.9 rules out pictures inside sync rows (no base64 in a `Product` payload), so the picture
needs its own call.

## 2. The idea in six lines

1. After the till saves or finds a picture, it **uploads the file** with `PUT sync/product-images/{productId}`: the
   raw bytes as the body, the SHA-256 of those bytes in `X-SSPOS-Image-Sha256`.
2. **Same hash as the portal holds → 200 `unchanged`**, nothing stored. Retries and repeats are free.
3. The till **removes** a product's picture with `DELETE sync/product-images/{productId}`.
4. Before a big run ("Find pictures", or the first run after the upgrade with an old catalogue), the till **asks what
   the portal already has** (`GET sync/product-images`, product id + hash, paged) and uploads only what is missing or
   different, **one at a time, throttled**.
5. Same sync key, headers and rules as `sync/push` (§3). JSON error body of §9. Its own rate limit.
6. `Product.imagePath` stays as it is (a local path). Nothing in push or pull changes.

## 3. Common rules for the three calls

| | |
|---|---|
| Auth | `Authorization: Bearer <sync.hub_api_key>` (the branch's sync key) and every §3 identity header (`X-SSPOS-Company-Id`, `X-SSPOS-Branch-Id`, `X-SSPOS-Register-Id`, `X-SSPOS-Contract: 1`, `X-SSPOS-App-Version`; `X-SSPOS-Store-Protocol` optional). Same 401 / 403 / 409 / 426 answers as `sync/hello` (§4.1). Never a key in the address |
| Who calls | Only the branch's main till (or a single till); an extra till never uploads (2026-11-02). Any branch's key may upload for the business's products: products are company-wide, and in a business with several shops the shop whose main till holds the file sends it |
| Scope | Only products of the key's business. A product of another business looks exactly like an unknown one: `404 product.not_found` |
| Errors | §9 body `{code, message, traceId, retryAfterSeconds, rejectedKey}`; `rejectedKey` = the product id where there is one. Codes: v1.4.1 ones (`request.invalid`, `auth.*`, `rate.limited`, `server.busy`, `server.error`) plus §7 below |
| Rate limit | Counted in the sync key's general limit (§17.12) **and** in its own: 60 calls a minute per sync key (portal setting). Over it → `429 rate.limited` + `Retry-After`. Upload one picture at a time, never in parallel. The till (0.1.83) makes at most one picture call every 2 seconds (30 a minute) |
| Switch | Portal not switched on → `501 image.not_available`, `Retry-After: 3600`, after the key check and before the body is read |
| Compression | None: no `Content-Encoding` (pictures are already compressed) |

## 4. The calls

### 4.1 `PUT /api/v1/sync/product-images/{productId}` — upload a product's picture

- **Address:** `productId` = the product's ULID, as in `Product.id`.
- **Headers:** §3 + `Content-Type` (`image/jpeg`, `image/png` or `image/webp`; must be the type of the bytes),
  `Content-Length`, `X-SSPOS-Image-Sha256` (lower-case hex of exactly the bytes sent). No `Idempotency-Key`: the
  hash is the key. Schema `schemas/product-image-upload-headers.schema.json`, sample `samples/upload-request.headers.json`.
  The till sends JPEG or PNG only (WebP is still accepted).
- **Origin (2026-11-02, optional):** `X-SSPOS-Image-Source` = `own` (the shop chose the picture), `library` (Open Food
  Facts by barcode, CC BY-SA), `found` (web image search, copyright unknown) or `unknown` (a picture from before the
  till sent the header). Trimmed, any case. Missing, empty or any other value is stored as `unknown`: the portal never
  refuses an upload over this header. A `200 unchanged` upload (same bytes) with a different known origin (`own`,
  `library`, `found`) changes only the origin the portal holds (no new file, same `updatedAt`); `unknown` never
  overwrites a known origin. A stored or replaced picture takes the origin sent with it.
- **Body:** the picture file's bytes. Not JSON, not multipart, not base64.
- **Limits:** at most **2 MB** (2,097,152 bytes) and **16 to 2000 pixels** wide and high. JPEG, PNG or WebP (no
  animation). EXIF orientation is honoured.
- **Reply 200** (`schemas/product-image-upload-reply.schema.json`): `productId`, `imageHash` (the hash the portal now
  holds), `result` = `stored` (first picture), `replaced` (another picture was held and is gone) or `unchanged`
  (this picture was already held; nothing written), `updatedAt`. Samples `samples/upload-reply.*.json`.
- **Idempotent:** the same bytes again (a retry after a timeout, a repeated sweep) → 200 `unchanged`.
- **Order:** the `Product` row must reach the portal first (push), else `404 product.not_found`: keep the picture
  waiting and send it after the next push.

### 4.2 `DELETE /api/v1/sync/product-images/{productId}` — the till removed the picture

No body. Reply 200 (`schemas/product-image-delete-reply.schema.json`): `result` = `deleted`, or `none` when the portal
held no till picture (idempotent). Use it when a product's picture is removed at the till (or `imagePath` is cleared).
Not needed when a product is archived or deleted: the portal stops showing those itself.

### 4.3 `GET /api/v1/sync/product-images?after={productId}&max={n}` — what the portal already has

The till pictures the portal holds for the business, in product id order, `max` per page (1 to 5000, default 1000).
`after` = the previous page's `nextAfter` (leave it out for the first page). Reply 200
(`schemas/product-image-list-reply.schema.json`): `images[]` (`productId`, `imageHash`, `updatedAt`, and since
2026-11-02 the added member `origin`: the origin the portal holds, see §4.1), `nextAfter`
(null on the last page), `hasMore`. Query schema `schemas/product-image-list-query.schema.json`. Samples
`samples/list-request.query.json`, `samples/list-reply*.json`. A product not in the list has no picture on the portal.

It is also the cheapest way to learn whether the portal has pictures switched on (`501 image.not_available` if not).
The till (0.1.83) lists after the upgrade, on "Find pictures" and once a week, at `max=200`, one page every 2 seconds.

## 5. What the till does

**Normal work (one picture at a time):** when a picture is saved for a product (auto picture a few seconds after a
create, a picture chosen by hand, a picture found by "Find pictures"), queue `(productId, file)`, and send it only once
the product's `Product` row has been acknowledged by push (product row first, picture after). A background worker
(never on the till's UI thread, never during a sale) sends the queue in order, one `PUT` at a time, at most one a
second, and remembers per product the last hash the portal accepted (`imageHash` of the reply). A picture removed at
the till queues a `DELETE`. The queue survives a restart; a product deleted before its turn is dropped.

**Sweep ("Find pictures", first run after the upgrade, a till that was offline for long):**
1. `GET sync/product-images` page by page (one call per page; the till uses `max=200`: 100,000 products ≈ 500 calls).
2. For each product with a local picture: hash the file; upload it when the portal has no hash or another hash.
3. ~~For each portal entry whose product has no local picture any more: `DELETE`.~~ **Dropped (2026-11-02).** In a
   business with several shops "no picture on this till" often means the file is on another shop's main till, or this
   till has not pulled the newest `imagePath` yet. The till deletes **only a picture it sent itself and then removed**,
   and repeats a lost `DELETE` until it gets 200. The portal never deletes a till picture on its own.
4. Throttle: at most one call every 2 seconds, back off on 429 / 503 (`Retry-After` wins) and on 5xx / no answer
   (1 → 2 → 5 → 15 minutes), pause while a sale is open on the main till, carry on later. A catalogue of thousands of
   pictures goes up over hours (5,000 ≈ 3 hours), which is fine.

**Hash what you send.** If the till resizes before sending (a file over 2 MB or 2000 px), it should keep the resized
copy as its local picture (or remember the hash of what it sent), so the next sweep compares the same bytes and does
not upload again.

**Errors (as agreed 2026-11-02):** 401 / 403 / 426 on a picture call: **pictures only** pause for a day; push and pull
never stop over a picture answer (a really wrong key is caught by push, as before). 409: as for push. A 404 on the list
call (portal without these calls) is treated like 501. 413 / 415 /
`image.dimensions`: make a compliant copy once (≤ 2000 px, JPEG quality 85) and send that; never the same bytes
again. `image.invalid`: show the product on Sync status; send again only when the picture changes. `image.hash_mismatch`,
429, 503, 5xx, timeouts: retry later, same bytes. `product.not_found` (rare, the product row goes first): retry after
10 min → 1 h → 6 h → then daily. `image.not_available`: stop; ask again after `Retry-After` (at least an hour), then at
most daily. 413 / 415 / `image.dimensions`: the till sends one compliant JPEG copy (≤ 1200 px, quality 85); refused
again → the picture is "refused" on Sync status.

## 6. What the portal does with a picture

- Checks the type from the bytes (not the header), the size and the pixels before decoding anything; then **draws the
  picture again** and saves a web copy (WebP, longest side at most 1200 px). Nothing of the original file is kept:
  EXIF (camera, GPS, times), ICC profiles and comments are gone; the EXIF orientation is applied first.
- Stores it on the portal's public files under a new name per picture (`till-images/{business}/{productId}-{hash}`),
  so a replaced picture never shows from a cache; the old file is removed.
- Shows it in the **online store** (order: the shop's own online photo > **the till's picture** > the shared
  catalogue's picture of the barcode > a placeholder) and on the portal's **Products** list and product page.
- **Origin on the public store (2026-11-02):** by default only `own` and `library` till pictures are shown; a `library`
  picture is credited "Photo: Open Food Facts (CC BY-SA)" under the product page photo, with one footer line while the
  business holds such pictures. `found` and `unknown` pictures are shown only when the shop turns on "Show found
  pictures" in the store builder; otherwise the store goes on to the catalogue picture or the placeholder, as if there
  were no till picture. The portal's own Products pages show every till picture, labelled with its origin.
- Logs `business, product, first 16 of the hash, size` only; never the bytes or the key.

## 7. New error codes (`error-codes.additions.json`, same shape as `licensing/samples/error-codes.json`)

| Code | HTTP | When |
|---|---|---|
| `image.not_available` | 501 | Portal not switched on yet (`Retry-After` 3600) |
| `product.not_found` | 404 | Not a product of this business (not pushed yet, deleted, another business's) |
| `image.too_large` | 413 | Over 2 MB |
| `image.unsupported_type` | 415 | Not JPEG / PNG / WebP, or `Content-Type` is not the bytes' type |
| `image.hash_mismatch` | 422 | `X-SSPOS-Image-Sha256` is not the body's SHA-256 |
| `image.dimensions` | 422 | Under 16 or over 2000 px |
| `image.invalid` | 422 | Cannot be read (damaged, animated) |

Plus v1.4.1's: `request.invalid` 400 (bad product id, missing `Content-Length` or hash header, bad `after`/`max`,
gzip body), `rate.limited` 429, `server.busy` 503 (another upload of the same product still being stored).

## 8. Later phase (not built): pictures set on the portal go to the tills

When a shop owner sets a product picture on the portal, the tills could show it too. Sketch, for discussion only:
the portal adds an optional member to the hub-owned `Product` payload, e.g. `imageUrl` (https address of the
portal's copy) and `imageHash`; the till downloads the file on demand (§18.9: a URL, never inline bytes) into its
`product-images` folder and fills its own `imagePath`. The portal would never send back a picture it got from the
same till (§19.2), and the newest of till and portal wins by `updatedAt`. The portal's table already has a
`source` (`till` / `portal`) for this. Nothing of it is in this proposal's calls.

**Till team's answer (Q10, 2026-11-02):** yes, later, as optional hub-owned members `Product.imageUrl` + `imageHash`
(a URL per §18.9, not a separate table); the till downloads on demand and fills its own `imagePath`. To be agreed
separately once uploads are live.

## 9. What the till team builds (checklist)

1. A background upload queue for pictures (§5), fed by every picture save, removal and "Find pictures".
2. `PUT`, `DELETE` and `GET` of §4 with the §3 headers, hash of the exact bytes, Content-Type from the file, and
   `X-SSPOS-Image-Source` (2026-11-02).
3. A resize step for files over 2 MB / 2000 px (keep the copy, or remember its hash).
4. The sweep of §5 at "Find pictures", after the upgrade, and after a long time offline.
5. The error handling of §5; refused pictures on the Sync status screen.
6. Tell us the first till version that has it; we switch the portal on when that version ships.

## 10. Open questions for the till team

Answered 2026-11-02 (`ANSWERS-2026-11-02.md`), in short: Q1 raw bytes; Q2 limits fine (the till sends ≤ 512 px hand
pictures, ~320–400 px found ones, JPEG / PNG only); Q3 the `X-SSPOS-Image-Source` header (§4.1, §6); Q4 the list call;
Q5 501 is enough; Q6 main till only; Q7 yes, `DELETE` on "remove picture", replace = new `PUT`; Q8 one call every
2 seconds; Q9 merged products keep their own picture, no call; Q10 later via `Product.imageUrl` + `imageHash` (§8).

- **Q1 — body:** raw bytes with `Content-Type` (our proposal; simplest for `HttpClient` + `ByteArrayContent`), or would
  you rather send `multipart/form-data`?
- **Q2 — limits:** are 2 MB and 2000 px right for the pictures "Find pictures" finds and the till's own camera /
  file picker? Do you already resize when saving to `product-images`?
- **Q3 — where pictures come from:** what are the sources of "Find pictures" and the auto picture (the shared product
  library, a web search, the supplier)? The portal shows them on a **public** online store: may those pictures be
  published, or should the till mark found pictures (e.g. a `X-SSPOS-Image-Source: library|found|own` header) so the
  shop owner can keep found ones off the store?
- **Q4 — how the till learns what the portal has:** the list call (§4.3), or would you prefer the portal to echo an
  `imageHash` member in the pulled `Product` row (a v1.4.1 payload change, so we left it out)?
- **Q5 — capability:** is "501 on the list call" enough for the till to know the portal supports pictures, or do you
  want a member in the `sync/hello` reply (e.g. `productImages: {maxBytes, maxPixels}`)?
- **Q6 — which till uploads:** only the main till holds `product-images`? A linked till never uploads?
- **Q7 — removing:** does the till ever clear `imagePath` or delete a picture file on purpose (so `DELETE` is
  needed), or is a picture only ever replaced?
- **Q8 — speed:** our limit is 60 picture calls a minute per sync key; your "Find pictures" runs at about one a
  second. Is a sweep at one upload a second acceptable on a shop's line, or should it be slower during trading hours?
- **Q9 — merged products:** when two products are merged at the till, should the picture move to the product kept
  (upload it there) and the other be deleted?
- **Q10 — later phase (§8):** do you want portal → till pictures at all, and if so as `Product.imageUrl` or a
  separate hub-owned table?

## Files

- `README.md` (this file)
- `error-codes.additions.json` — the seven new codes, in the v1.4.1 `error-codes.json` shape
- `schemas/product-image-upload-headers.schema.json`, `product-image-upload-reply.schema.json`,
  `product-image-delete-reply.schema.json`, `product-image-list-query.schema.json`, `product-image-list-reply.schema.json`
- `samples/upload-request.headers.json`, `upload-reply.{stored,replaced,unchanged}.json`,
  `delete-reply.{deleted,none}.json`, `list-request.query.json`, `list-reply.json`, `list-reply.last-page.json`,
  `error-reply.{not-available,product-not-found,too-large,hash-mismatch,rate-limited}.json`

Portal tests: `tests/Feature/Contract/ProductImagesProposalTest.php` (samples valid against these schemas and the
v1.4.1 error-reply schema; codes only added), `tests/Feature/Sync/ProductImagesApiTest.php` (the calls, each reply
checked against these schemas), `tests/Feature/Sync/ProductImageOriginTest.php` (the origin header, 2026-11-02).
