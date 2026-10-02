# Public trial request API (module 1.10)

`POST /api/v1/public/trial-requests` · no auth · JSON in, JSON out · hosted example form: `GET /trial`.

**Body** (camelCase): `businessName`\* (≤160), `contactName`\* (≤120), `email`\*, `phone`\* (UK, e.g. `07700 900123`),
`town`\* (≤80), `postcode`\* (≤10), `shopsCount`\* (1–20), `tillsCount`\* (1–100, at least one per shop),
`businessType`\* (till `BusinessType` name: `ConvenienceOffLicence`, `Newsagent`, `GroceryHalalButcher`,
`ClothingFootwear`, `PhoneElectronics`, `Salon`, `DryCleaner`, `CashAndCarry`, `Pharmacy`, `Other`),
`currentSystem` (≤160), `marketingConsent` (bool, default false), `utm` `{source, medium, campaign}`,
`captchaToken` (Cloudflare Turnstile token, required once `TURNSTILE_SECRET` is set), `website` (honeypot: hidden,
always empty). \* = required.

**201** `{"reference": "TR-7K3QZD", "message": "Thank you. We have your trial request and one of our team will be in touch shortly to set up your free trial."}`
Show `message` to the visitor; staff can search the lead list for the reference. A request whose email or phone
matches an open lead is added to that lead as a note (same 201, that lead's reference).

**Errors** (shared envelope `{code, message, traceId, retryAfterSeconds, rejectedKey, details?}`, en-GB messages):

| Status | `code` | When |
|---|---|---|
| 400 | `request.invalid` | Validation; `details.fields` = `{"email": ["Enter a valid email address…"]}` |
| 403 | `cors.origin_not_allowed` | Browser origin not in `PUBLIC_FORM_ORIGINS`. Nothing is stored. The preflight is answered and the 403 echoes the origin in `Access-Control-Allow-Origin` (no credentials), so the page can read the body and show "this website isn't allowed yet" instead of a network error |
| 422 | `captcha.failed` | Turnstile token missing, wrong or expired: reset the widget and resend |
| 429 | `rate_limited` | 5 requests an hour per IP (400s do not count) or 3 a day per email; see `retryAfterSeconds` |

**Setup** (`.env`): `PUBLIC_FORM_ORIGINS=https://switchandsave.co.uk,https://www.switchandsave.co.uk` (exact
origins, no trailing slash; our own `/trial` page is always allowed; server-to-server calls without `Origin` pass).
`TURNSTILE_SITE_KEY` (public, used by the widget on the website and `/trial`) and `TURNSTILE_SECRET` (server only).
Without the secret the check is skipped in local/testing and every request fails with `captcha.failed` elsewhere.
The website adds the widget with `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer>`
and `<div class="cf-turnstile" data-sitekey="…" data-action="trial">` (the portal refuses a token without action
`trial` or from a host that is not ours: APP_URL, PUBLIC_FORM_ORIGINS or TURNSTILE_HOSTNAMES); the token arrives in the hidden `cf-turnstile-response` field.

**Example** (plain JS on the website):

```js
const form = document.querySelector('#trial-form');
form.addEventListener('submit', async (event) => {
  event.preventDefault();
  const f = Object.fromEntries(new FormData(form));
  const q = new URLSearchParams(location.search);
  const res = await fetch('https://YOUR-BACKOFFICE-HOST/api/v1/public/trial-requests', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ ...f, shopsCount: Number(f.shopsCount), tillsCount: Number(f.tillsCount),
      marketingConsent: f.marketingConsent === 'on', captchaToken: f['cf-turnstile-response'],
      utm: { source: q.get('utm_source'), medium: q.get('utm_medium'), campaign: q.get('utm_campaign') } }),
  });
  const body = await res.json();
  if (res.status === 201) { form.outerHTML = `<p>${body.message} Your reference: ${body.reference}</p>`; return; }
  // 403 cors.origin_not_allowed: this website is not on PUBLIC_FORM_ORIGINS yet (ask Switch & Save to add it).
  alert(body.message); // field errors: body.details?.fields; after 422 call turnstile.reset()
});
```
