# Licence token verification (for the EPOS / .NET team)

Status: draft, 2026-09-24. Companion to `docs/specs/licence-api-v1.md` (the API). Portal side: module 1.4,
`app/Domain/Licensing/Signing/`.

The portal gives every till a signed **licence token** on `activate` and `check-in`. The till stores it and
checks it **offline** with the portal's public key, so it can keep trading without internet until `validUntil`.

## 1. Format

A JWS in compact form (RFC 7515), signed with **EdDSA over Ed25519** (RFC 8032, RFC 8037):

```
BASE64URL(header) "." BASE64URL(payload) "." BASE64URL(signature)
```

- base64url **without padding** (`-` and `_`, no `=`). JSON is UTF-8, slashes not escaped.
- The signature is 64 bytes over the ASCII bytes of `BASE64URL(header) "." BASE64URL(payload)`, exactly as
  received. Never re-encode the JSON before verifying.

Header (always exactly these three fields today):

```json
{"alg":"EdDSA","kid":"lk2026-01","typ":"sspos-licence+jwt"}
```

| Field | Rule |
|---|---|
| `alg` | Must be `EdDSA`. Reject anything else (`none`, `HS256`, `ES256`, …). |
| `typ` | Must be `sspos-licence+jwt`. |
| `kid` | Which portal key signed it. Format `lk<year>-<nn>`, e.g. `lk2026-01`. |
| `crit` | Must not be present. Reject if it is. |

Payload claims (per the licence spec; value formats as built in module 1.5):

| Claim | Meaning |
|---|---|
| `iss` | Always `sspos-portal`. Reject anything else. |
| `iat` | Issued at, Unix seconds (UTC). |
| `jti` | Token id (ULID). Sent back as `tokenId` on check-in. |
| `lic`, `keyLast4` | Licence id and last 4 characters of the key. |
| `companyId`, `branchId`, `registerId` | Same ids as the sync entities. Must match the till's own. |
| `deviceId` | The PC the licence is bound to. Must match this PC. |
| `status`, `plan`, `features` | `trial`/`active`/`grace`/`expired`/`suspended`/`revoked`, plan code, feature flags. |
| `expiresAt`, `graceDays` | Paid-until date (ISO-8601 UTC `Z`) and grace days after it. |
| `validUntil` | ISO-8601 UTC `Z`. The till must stop trading after this without a new token. |

Unknown claims must be ignored (we may add some).

## 2. Keys

- The portal holds the private keys; they never leave it. The till only ever has **public** keys.
- **Built-in key(s):** ship the current public key(s) inside the till build, as `kid → x` pairs.
- **Key set:** `GET /api/v1/licence/keys` returns a JWKS with the active key and retired keys that still verify:

  ```json
  {"keys":[{"kid":"lk2026-01","kty":"OKP","crv":"Ed25519","x":"<43 chars base64url>","use":"sig"}]}
  ```

  `x` is the raw 32-byte Ed25519 public key, base64url without padding.
- **Rotation:** we add a new `kid` and sign new tokens with it. The old key keeps verifying for 60 days, then it
  disappears from the key set. Tokens live at most 14 days, so a till that checks in at least once in that time
  always holds a token signed by a key it can verify.

## 3. How to verify (pseudo-code)

```text
function verifyLicenceToken(token, trustedKeys /* kid -> 32-byte public key */):
    if token.length > 8192: reject "malformed"
    parts = token.split(".")
    if parts.count != 3 or any part is empty: reject "malformed"

    headerBytes = base64UrlDecodeStrict(parts[0])     // no padding, url alphabet only
    header      = parseJsonObject(headerBytes)
    if header.alg != "EdDSA":               reject "malformed"
    if header.typ != "sspos-licence+jwt":   reject "malformed"
    if header has "crit":                   reject "malformed"
    publicKey = trustedKeys[header.kid]
    if publicKey is null:                   reject "unknown key"   // refresh key set when online, then retry once

    signature = base64UrlDecodeStrict(parts[2])
    if signature.length != 64:              reject "malformed"
    signingInput = ASCII(parts[0] + "." + parts[1])
    if not Ed25519.Verify(publicKey, signingInput, signature): reject "invalid signature"

    claims = parseJsonObject(base64UrlDecodeStrict(parts[1]))   // only now trust the payload
    if claims.iss != "sspos-portal":        reject "invalid claims"
    if claims.deviceId != thisPc.deviceId:  reject "device mismatch"
    if claims.registerId != thisTill.registerId: reject "wrong till"
    if utcNow() >= parse(claims.validUntil): reject "expired - go online"   // see clock rules
    return claims
```

.NET libraries (there is no Ed25519 in the base class library):

- **NSec** (`NSec.Cryptography`, libsodium underneath, same library as the portal):
  ```csharp
  var alg = SignatureAlgorithm.Ed25519;
  var key = PublicKey.Import(alg, xBytes, KeyBlobFormat.RawPublicKey);
  bool ok = alg.Verify(key, Encoding.ASCII.GetBytes(parts[0] + "." + parts[1]), signatureBytes);
  ```
- **BouncyCastle** (`BouncyCastle.Cryptography`):
  ```csharp
  var verifier = new Ed25519Signer();
  verifier.Init(false, new Ed25519PublicKeyParameters(xBytes, 0));
  var input = Encoding.ASCII.GetBytes(parts[0] + "." + parts[1]);
  verifier.BlockUpdate(input, 0, input.Length);
  bool ok = verifier.VerifySignature(signatureBytes);
  ```
- base64url: `System.Buffers.Text.Base64Url` (.NET 9+) or `Microsoft.IdentityModel.Tokens.Base64UrlEncoder`.

Interoperability check: the portal's tests reproduce the RFC 8037 appendix A.4 example byte for byte (key
`d = nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A`, payload `Example of Ed25519 signing`). Please run the same
vector through your verifier, then the sample below.

## 4. Sample (TEST ONLY)

> **TEST ONLY.** Signed with the public RFC 8037 test key under the fake kid `lk-test-01`. Never put this key
> in a production build. Real kids look like `lk2026-01`.

JWKS:

```json
{"keys":[{"kid":"lk-test-01","kty":"OKP","crv":"Ed25519","x":"11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo","use":"sig"}]}
```

Token (one line):

```text
eyJhbGciOiJFZERTQSIsImtpZCI6ImxrLXRlc3QtMDEiLCJ0eXAiOiJzc3Bvcy1saWNlbmNlK2p3dCJ9.eyJpc3MiOiJzc3Bvcy1wb3J0YWwiLCJpYXQiOjE3OTAyNDA0MDAsImp0aSI6IjAxSzVYVEVTVDAwMDAwMDAwMDAwMDAwMDAxIiwibGljIjoiMDFLNVhURVNUMDAwMDAwMDAwMDAwMDAwMDIiLCJrZXlMYXN0NCI6IlE3SzIiLCJjb21wYW55SWQiOiIwMUs1WFRFU1QwMDAwMDAwMDAwMDAwMDAwMyIsImJyYW5jaElkIjoiMDFLNVhURVNUMDAwMDAwMDAwMDAwMDAwMDQiLCJyZWdpc3RlcklkIjoiMDFLNVhURVNUMDAwMDAwMDAwMDAwMDAwMDUiLCJkZXZpY2VJZCI6IlRFU1QtREVWSUNFLTAwMDEiLCJzdGF0dXMiOiJhY3RpdmUiLCJwbGFuIjoic3RhbmRhcmQiLCJmZWF0dXJlcyI6WyJzdG9jayIsImxveWFsdHkiXSwiZXhwaXJlc0F0IjoiMjAyNi0xMC0zMVQyMzo1OTo1OVoiLCJncmFjZURheXMiOjcsInZhbGlkVW50aWwiOiIyMDI2LTEwLTA4VDA5OjAwOjAwWiJ9.9rlVcb7agb9PmFKncD4XfIrsWUOqvTcVMa7clnltZCINcaE_7SsudKdtmMryobosNHP_jFXUC8sMAqpxWSKBCg
```

Decoded:

```json
{"alg":"EdDSA","kid":"lk-test-01","typ":"sspos-licence+jwt"}
```

```json
{"iss":"sspos-portal","iat":1790240400,"jti":"01K5XTEST00000000000000001","lic":"01K5XTEST00000000000000002","keyLast4":"Q7K2","companyId":"01K5XTEST00000000000000003","branchId":"01K5XTEST00000000000000004","registerId":"01K5XTEST00000000000000005","deviceId":"TEST-DEVICE-0001","status":"active","plan":"standard","features":["stock","loyalty"],"expiresAt":"2026-10-31T23:59:59Z","graceDays":7,"validUntil":"2026-10-08T09:00:00Z"}
```

`iat` 1790240400 = 2026-09-24T09:00:00Z; `validUntil` = min(iat + 14 days, expiresAt + 7 days) = 2026-10-08T09:00:00Z.
Flipping any character of the token must make verification fail.

## 5. Clock rules

From the licence spec:

- All times are UTC. `validUntil = min(issuedAt + 14 days, expiresAt + graceDays)`; days are exact 24-hour
  periods. The portal computes it; the till only compares against it.
- The till checks in every `checkInEverySeconds` (86400 = daily) and replaces its token with the one in the
  reply. Every reply carries `serverTimeUtc`.
- Check-in on a suspended/expired/revoked licence still returns **200** with a signed token carrying that status:
  obey the status in the token (it is signed), not just the HTTP code.

Proposed for the till (please confirm):

- Treat the token as usable while `utcNow < validUntil`. After that, block sales until a successful check-in.
- Record the highest time ever seen (max of `serverTimeUtc`, `iat`, local UTC clock). If the local clock is later
  found more than 5 minutes behind that value, treat the clock as rolled back and require an online check-in.
- When online, use `serverTimeUtc` rather than the PC clock to decide `validUntil`; warn staff if the PC clock is
  off by more than 5 minutes.
- Show a warning from 3 days before `validUntil` ("connect to the internet to renew your licence").

## 6. Open points

- How the till learns a new `kid` it does not have built in: accept the JWKS from `GET /api/v1/licence/keys`
  over HTTPS (simple; trust = TLS), or only trust keys shipped in signed app updates (stronger; needs an update
  before each rotation). We suggest built-in keys + JWKS over HTTPS with certificate pinning.
- ~~Final value formats of `expiresAt` / `validUntil`~~ Fixed by module 1.5: ISO-8601 UTC strings with `Z`, whole
  seconds (`2026-10-08T09:00:00Z`); `iat` stays Unix seconds. Real replies and tokens:
  `docs/specs/licence-api-samples/` (signed with the test key of section 4).
