# Shared building blocks (module 0.5)

Reusable pieces every module should use instead of writing its own. Backend lives in `app/Domain/Shared/`,
frontend in `resources/js/components/shared/`.

## Backend

### Audit log — `Actions\RecordAudit`, `Models\AuditLog`

Record every admin/tenant action that changes something. The actor comes from the `admin` guard, then `web`;
pass `actor:` from jobs and till APIs. Secrets (password, licence key, API key, token, `*_hash`, …) are replaced
with `[redacted]` by `Support\Redactor`. Entries cannot be updated or deleted.

```php
app(RecordAudit::class)->handle('licence.suspended', $licence, ['status' => 'active'], ['status' => 'suspended'], ['reason' => $reason]);
app(RecordAudit::class)->handle('sync.key_issued', $branch, null, null, actor: $admin, companyId: $branch->company_id);
```

`company_id` defaults to the subject's `company_id`. `AuditLog` is not tenant-scoped: tenant screens must filter by `company_id`.

### ULIDs — `Support\Ulid`, `Rules\ValidUlid`

```php
$id = Ulid::new();                          // "01K5VB..." (26 chars, upper case)
Ulid::isValid($request->input('saleId'));  // strict: upper-case Crockford base32
'branchId' => ['required', new ValidUlid],
```

### Money and quantities — `Casts\MoneyCast`, `Casts\QuantityCast`, `Support\Money`

Normalised strings, never floats; round half away from zero; non-numeric input throws `InvalidArgumentException`.

```php
protected function casts(): array { return ['price' => MoneyCast::class, 'cost_price' => QuantityCast::class]; }
$product->price = 1.005;        // "1.01"   ·  "1.4500" → "1.45"  ·  QuantityCast: "2" → "2.0000"
Money::add($a, $b); Money::sum($lines->pluck('total')); Money::compare('1.5', '1.50'); // 0
```

### API dates — `Support\ApiDate`, `Casts\UtcDateTimeCast`

Always ISO-8601 UTC with `Z`. Strings with no offset are read as UTC.

```php
ApiDate::format($licence->expires_at);           // "2026-10-01T00:00:00Z"
protected function casts(): array { return ['completed_at' => UtcDateTimeCast::class]; } // toArray() → "...Z"
```

### API errors — `Exceptions\ApiException`, `Exceptions\ApiExceptionRenderer`, `Http\Middleware\AssignTraceId`

Every error under `api/*` is rendered as `{code, message, traceId, retryAfterSeconds, rejectedKey}`. Validation →
400 `request.invalid`, 404 → `not_found`, 429 → `rate_limited` (from `Retry-After`), 5xx → `server.error` (no
details). `traceId` is a per-request ULID, also in the `X-Trace-Id` header and the log context. Messages are en-GB
for the shop owner.

```php
throw ApiException::notFound('licence.not_found', 'We could not find this licence key. Check it and try again.');
throw new ApiException('licence.bound_to_other_device', 'This key is already used on another PC.', 409);
throw ApiException::rowRejected('Sale:01K5...:1', 'Sale 01K5... has a total with more than 2 decimal places.');
```

### Tables — `Support\TableQuery`

Reads `search`, `sort`, `direction`, `page`, `perPage` (10/25/50/100) and applies only whitelisted columns.
Returns `{data, meta: {page, perPage, total, lastPage, search, sort, direction}}` for `DataTable`.

```php
$products = TableQuery::from($request)->searchable(['name', 'sku'])->sortable(['name', 'price'])->defaultSort('name')
    ->paginate(Product::query(), fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price]);
return Inertia::render('app/products/index', ['products' => $products]);
```

## Frontend (`@/components/shared/...`)

### `page-header.tsx`

```tsx
<PageHeader title="Licences" description="One licence per till." actions={<Button>Issue licence</Button>} />
```

### `stat-card.tsx`

`delta.goodWhen` sets which direction is green (default up). Use `"down"` for refunds, voids, overdue.

```tsx
<StatCard label="Sales today" value="£1,204.50" delta={{ value: '12%', direction: 'up', label: 'vs last Tuesday' }} icon={PoundSterling} />
<StatCard label="Refunds" value="£32.00" delta={{ value: '4%', direction: 'up', goodWhen: 'down' }} hint="3 refunds" />
```

### `status-badge.tsx`

Maps a status to a calm tone (success, warning, danger, info, neutral). Defaults cover licence/tenant/lead
statuses; pass `tones` to add or override. Label is the status in sentence case.

```tsx
<StatusBadge status={licence.status} />
<StatusBadge status="awaitingStock" tones={{ awaitingStock: 'warning' }} />
```

### `empty-state.tsx`

```tsx
<EmptyState icon={Package} title="No products yet" body="Products added on the till appear here after sync." action={<Button>Add product</Button>} />
```

### `confirm-dialog.tsx`

AlertDialog wrapper. `onConfirm` may return a promise; the dialog stays open with a spinner until it settles.

```tsx
<ConfirmDialog trigger={<Button variant="outline">Suspend</Button>} title="Suspend this licence?" description="The till stops trading at its next check-in."
    confirmLabel="Suspend licence" destructive onConfirm={() => router.post(route('admin.licences.suspend', licence.id))} />
```

### `data-table/`

Typed TanStack table with server-side paging, sorting and debounced search through Inertia (`router.get`,
`preserveState`, `replace`). Columns opt in to sorting with `enableSorting: true` (the column id is the server
sort key). Filters are URL params: use `useTableQuery().update({...})` in filter controls.

```tsx
const columns: ColumnDef<Product>[] = [{ accessorKey: 'name', header: 'Name', enableSorting: true }, { accessorKey: 'price', header: 'Price' }];
<DataTable columns={columns} data={products.data} meta={products.meta} only={['products']} searchPlaceholder="Search products"
    filters={<StatusFilter />} onRowClick={(p) => router.visit(route('app.products.show', p.id))} />
```

New shadcn primitives added for these: `ui/table.tsx`, `ui/alert-dialog.tsx`.

## Toaster (`resources/js/components/shared/toaster.tsx`)

Mounted once in `AdminLayout` and `AppLayout` — never mount it in a page. From a controller:
`return back()->with('success', 'Plan saved.')` (or `'error'`). From the client: `showToast('Copied', 'success')`.
Action validation errors on keys `status, branch, branch_id, register, user, user_id, plan` also show as toasts.
