# pharmacy-dmd (portal-written, from the till 0.1.85 pack)

Not an EPOS file. The 0.1.85 pack describes `GET /api/v1/pharmacy/dmd/latest` and its zip only in prose
(`docs/contracts/portal-api-0.1.85/docs/web-portal-api/PORTAL-CHANGES-pharmacy.md` item 12; not in `openapi.yaml`).
This folder turns that prose into files the tests check against (W4, `docs/verticals-0.1.85.md` "dm+d release server"):

- `schemas/dmd-manifest-reply.schema.json`: the 200 manifest, from the field table.
- `samples/dmd-manifest-reply.json`: the sample in item 12, copied as written.
- `error-codes.additions.json`: `dmd.no_release` (404, named by the contract) and the portal's own `dmd.not_available`
  (501), `dmd.not_pharmacy` (403) and `dmd.link_invalid` (403). The till acts on the status, not the code.

When EPOS publishes a schema for this endpoint, replace these files with theirs.
