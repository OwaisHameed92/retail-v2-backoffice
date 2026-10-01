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

## Added by module 1.3 (licences)

### `@/lib/http` — `sendJson()`

JSON requests outside Inertia, for replies that must not become a page prop or a session flash (new licence keys,
the top-bar search). Sends the `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`; returns `{ok, status, data, errors, message}`
with the first validation message per field and a readable message for 401/403/404/419/422/429/5xx.

```tsx
const result = await sendJson<IssuedKeysReply>('POST', route('admin.licences.reissue', licence.id));
if (!result.ok) showToast(result.message ?? 'Something went wrong.', 'error');
```

### `@/hooks/use-min-width` — `useMinWidth(px)`, `useBreakpoint()`

Media-query hooks (Tailwind breakpoints). Use them to build a smaller `DataTable` column set on narrow screens:
hiding a sortable column's header with `hidden md:inline` still renders its sort icon.

### Licence key dialog (`components/admin/licences/licence-keys-dialog.tsx`, `reveal-keys.ts`)

The one-time "Licence key created" dialog, mounted once in `AdminLayout` (like the toaster). Show keys from a JSON
reply with `revealLicenceKeys({ keys, title? })`; it offers Copy, Copy all, "Email this key to the owner" (for
admins with `licences.manage` or `tenants.manage`) and asks before closing when a key was neither copied nor
emailed. `requestKeys(url, { only })` in `issue-keys.ts` does POST → reveal → `router.reload`.

### `LicenceStatusBadge` (`components/admin/licences/licence-status-badge.tsx`)

`StatusBadge` with licence tones and labels, plus a tooltip (and screen-reader text) with what the status means or
why the till is locked: `<LicenceStatusBadge status={row.status} reason={row.statusReason} />`.

### Admin search palette (`components/admin/admin-search.tsx`)

The top bar's "Search tenant, licence key, device ID" opens a ⌘K / Ctrl K / "/" palette backed by
`GET /admin/search?q=` (`AdminSearchController`). Later modules can add result groups to that endpoint.

## UI redesign additions (see `docs/ui-redesign-status.md`)

All props of the components above stayed compatible; these are additions. Colours only through tokens.

### Layout props
- `AdminLayout` takes optional `breadcrumbs` (top-bar trail; default is the sidebar group + section) and
  `width="default" | "narrow" | "wide"`. Content is centred at `max-w-7xl`; never add page padding yourself.
- Admin nav items carry a `group` (`Customers`, `Billing`, `Operations`, `Settings`) in `admin-nav.ts`; a new
  module sets `route` and keeps its group. Tenant nav groups live in `components/app-sidebar.tsx`.

### `page-header.tsx` (extended)
```tsx
<PageHeader title={tenant.name} status={<StatusBadge status={tenant.status} />} back={{ href: route('admin.tenants.index'), label: 'Tenants' }}
    media={<InitialsAvatar name={tenant.name} shape="square" size="lg" />} description="Legal name · Customer since 24 Sept 2026"
    actions={<Button>Edit</Button>} tabs={<PageTabs … />} />
```

### `stat-card.tsx` (extended) — `StatGrid`, `KpiCard`
`tone` (primary, success, warning, danger, neutral) colours the icon circle; `chart={<AreaSparkline values={[…]} />}` (from `trend-chart.tsx`); `href` makes the card a link.
```tsx
<StatGrid><StatCard label="Failed" value={3} hint="Last 30 days" icon={CircleAlert} tone="danger" /></StatGrid>
```

### `data-table/` (extended)
Column `meta`: `align: 'right'` for numbers/money; `mobile: 'title' | 'aside' | 'field' | 'actions' | 'hidden'`
(defaults: first column title, `status` aside, `actions` actions, others the first four fields). Phones get a card
list automatically; pass `renderMobileCard` for a custom card or `mobile="table"` to opt out.

### `entity-cell.tsx` — `EntityCell`, `InitialsAvatar`
First column of lists: `<EntityCell name={row.name} subline={row.email} shape="square" />` (square for businesses,
circle for people, `icon` for things like licences, `href` to link the name).

### `page-tabs.tsx`
Underline tabs. Link tabs `{ label, href, active, count }` for URLs; button tabs `{ label, value, count, badge }`
with `value`/`onChange` for in-page panels (panel id `tab-panel-<value>`).

### `section-card.tsx`
`<SectionCard title description actions footer flush>` — the standard card with header; `flush` for tables.

### `form-section.tsx` — `FormCard`, `FormSection`, `FormGrid`, `FormField`
```tsx
<FormCard>
    <FormSection title="Business details" description="Sent to the till.">
        <FormGrid><FormField id="name" label="Business name" error={errors.name}><Input id="name" aria-invalid={!!errors.name} /></FormField></FormGrid>
    </FormSection>
</FormCard>
```
`FormField` props: `optional`, `help`, `error`, `labelAside`.

### `sticky-form-bar.tsx`
`<StickyFormBar message="You have unsaved changes."><Button variant="outline">Cancel</Button><Button type="submit">Save changes</Button></StickyFormBar>` under long forms.

### `timeline.tsx` — `Timeline`, `TimelineChanges`
Audit/activity lists: items `{ id, icon, tone, title, time, body }`; `<TimelineChanges changes={[{ label, from, to }]} />` for diffs.

### `description-list.tsx`
`<DescriptionList items={[{ label, value, mono, wide }]} layout="grid" | "rows" />`; empty values show "Not set".

### `row-actions.tsx`
`<RowActions label="Actions for Khan Mini Mart" actions={[{ label: 'Edit', icon: Pencil, href }, { label: 'Suspend', destructive: true, onSelect }]} />`
Stops row clicks; destructive items go last behind a separator and should open a `ConfirmDialog`.

### `mobile-card-list.tsx`
`MobileCardList` renders `{ title, aside, fields, actions }` cards (DataTable uses it). For a trend line use
`AreaSparkline` (`trend-chart.tsx`).

### UI primitives added or changed
`ui/textarea.tsx` (new), `Badge` variants `success | warning | danger | info | neutral`, `Alert` variants
`success | warning | info` (destructive is now a soft red panel). Shell pieces for layouts live in
`components/shell/` (`SidebarNav`, `ShellSidebar`, `Topbar`, `SearchTrigger`, `HelpMenu`, `NotificationsMenu`, `ThemeSubmenu`).

## Added by module 1.6 (leads)

- `LeadStatusBadge` (`components/admin/leads/lead-status-badge.tsx`): `StatusBadge` with lead tones and labels; `withHelp` adds a tooltip on what the status means.
- `FollowUp` (`components/admin/leads/follow-up.tsx`): a follow-up time as "Today 14:30" / "Tomorrow 09:00" / "3 Oct 09:00", red with an alarm icon when overdue, amber when due today (Europe/London).
- `LeadStats` type (`components/admin/leads/types.ts`) matches `App\Domain\Leads\Queries\LeadStats::compute()->toArray()`, for the admin dashboard (1.9).

## Added by module 1.8 (billing)

- `BusinessPicker` (`components/admin/licences/licence-filters.tsx`) is exported: `<BusinessPicker open onOpenChange title description onPick={(id, name) => …} />` searches tenants through the admin search endpoint. Used by the billing filters and "New invoice" / "Record payment".
- `InvoiceStatusBadge` (`components/admin/billing/invoice-status-badge.tsx`): `StatusBadge` with invoice tones and labels ("Partly paid"); `withHelp` adds a tooltip.
- `RecordPaymentDialog`, `CreateInvoiceDialog` (`components/admin/billing/`): self-contained dialogs taking a `{ id, name }` company; they fetch open invoices / a live invoice preview when not given. `TenantBillingPanel` is the tenant page's Billing tab.
- `components/admin/billing/money.ts`: `toPence`, `fromPence`, `formatPence` for exact client-side sums in pence (dialogs only; the server does the real maths).
- `InvoiceDocument` (`components/admin/billing/invoice-document.tsx`): the print-style invoice preview, fed by the same data as the PDF.
- Backend: `App\Domain\Billing\Support\BillingFormat::money('1234.5')` → "£1,234.50" without floats; `BillingDates` for London calendar dates and ranges ("1 Oct – 31 Oct 2026"); `App\Domain\Mail\Contracts\RendersAttachment` for mail attachments built at send time.


## Design system v2 foundation (see `docs/design/DESIGN-SYSTEM-v2.md`)

Tokens live only in `resources/css/app.css` (v2 palette, light + dark): `chrome`, `chrome-active`,
`chrome-foreground`, `chrome-muted`, `chrome-hover/border/input/glow`, `primary`/`primary-hover`/`primary-soft`,
`success`/`info`/`violet`/`warning`/`danger` each with `-foreground` (text on soft) and `-soft`, `rounded-card`,
`shadow-card`, `text-kpi`. The shadcn sidebar variables map to the chrome tokens. No raw hex in components.

### Shell (`components/shell/`)

- `ShellFrame` — the light frame (pass 2): white full-height sidebar on the left, the 64px top bar sticky to its
  right, page on the canvas. Props `storageKey`, `header`, `sidebar`, optional `banner` + `bannerHeight` (fixed above
  both). Sets `--shell-banner`, `--shell-top` and `--app-header-height`.
- `Topbar` (`home`, `search`, `actions`) with the mint wash + `BrandWaves`; `TopbarStart` (hamburger, mark on
  phones), `SearchTrigger` (`placeholder`, optional `shortPlaceholder` below xl, ⌘K on one line, icon-only on
  phones), `TopbarDivider`, `TopbarBreadcrumbs` (rendered above the page content), `HelpMenu` (chat bubble),
  `NotificationsMenu` (`unread` dot — only from real data), `AccountTrigger` (green avatar + name + role).
- `ShellSidebar` (`homeHref`, `areaLabel`, `groups`, `pinned`, `header`) — white sidebar: full logo + area label at
  the top (`SidebarLogo`), Settings pinned at the bottom; no footer brand card (pass 3).
- `BrandWaves` — decorative wave lines for `bg-chrome-frame` / `bg-brand-wash` surfaces (top bar, hero, auth panel).
  `SidebarNav` items accept `count` (green pill) and `live` (green dot) besides `badge`/`soon`.
- Admin nav (`admin-nav.ts`): groups Overview/Customers/Billing/Operations/Communications/Settings;
  `activePattern` may be an array; `countKey` reads the optional `admin.navCounts` shared prop (not sent by the
  backend yet — the Leads pill appears once it is).

### Dashboard building blocks (`components/shared/`)

- `kpi-card.tsx` — `KpiCard` (`label`, `icon`, `tone`, `value` — `null` shows "—" + "No data yet", `delta`,
  `series` for the sparkline, `footer`, `menu` slot, `href`) and `KpiGrid`. `StatCard` is unchanged.
- `trend-chart.tsx` — Recharts `AreaSparkline` and `TrendChart` (`variant` line with markers + soft area, or bar;
  hover tooltip); `ChartTone`, `toneVar`, `toneCircle`.
- `chart-card.tsx` — `ChartCard` (`title`, `subtitle`, `icon`, `controls`, `stat`, `footer`), `SegmentedControl`
  (12W/6M/1Y, active filled primary), `StatPill`.
- `attention-list.tsx` — `AttentionList` (`items: {id, label, tone, text, at, href}`, `total`, empty copy).
- `overview-tile.tsx` — `OverviewTile`; `quick-actions.tsx` — `QuickActions` (first `primary` filled);
  `health-list.tsx` — `HealthList` (`healthy|degraded|down|unknown`, summary pill); `welcome-banner.tsx` —
  `WelcomeBanner` + `greeting()`.
- `status-badge.tsx` — new `violet` tone, `StatusPill` (label pill without dot), `pillToneClasses`.
- `entity-cell.tsx` — `EntityCell` defaults to the soft grey avatar (`tone="neutral"`); `InitialsAvatar` has `tone`.
- `@/lib/relative-time` — `relativeTime(iso)` → "2h ago".

## Added by module 1.11 (licence form)

- `LicenceFormFields` (`components/admin/licences/licence-form-fields.tsx`): tills allowed, trial/full cards, length + unit, start, feature checkboxes with the till name ("Portal only" without one). Helpers `licenceValues()`, `licencePayload()`, `describeLicence()`. Used by `BranchLicenceDialog`, the tenant wizard and `ApproveLicenceSection` (lead approval).
- `BranchLicenceStrip` (under each branch card: "2 of 3 till keys in use", kind, length, features, "Licence settings"), `BranchLimitsDialog` (multi-branch and branches allowed), `ActivateByDialog` (licence page).
- Backend: `Licensing\Data\LicenceFormData` (branch settings with in use / allowed, company limits, options, plan defaults), `Tenancy\Support\TenantLimits`.

## Added by module 1.9 (admin dashboard)

- `components/admin/dashboard/`: `RevenueCard` (chart card with 12W/6M/1Y, total and "% vs previous" pill, lock state without billing access), `RecentTenantsCard` (table on desktop, card list on phones), `types.ts` matching `AdminDashboardData::forViewer()` and `RevenueChart::for()`.
- `KpiCard.emptyText` and the new `OverviewTile.emptyText` take a ReactNode (used for the "Needs billing access" lock hint). `OverviewTile` shows a flat delta in muted grey. `HealthList` no longer counts "unknown" rows as a problem: with some rows unmonitored the pill reads "Monitored systems healthy".
- Backend: `Admin\Queries\AdminDashboard` (`forAdmin`, `revenueFor`, `forget`), `Leads\Queries\LeadNavCount` (shared as `admin.navCounts.leads`), `Shared\Support\SchedulerHeartbeat`.
## Added by module 2.9B (sync conflicts, tenant portal)
- `components/app/sync/`: `types.ts` (matches `SyncConflictList::for()` / `SyncConflictDetail`), `format.tsx` (`KindPill`, `ConflictStatusBadge`, `ClashBadge`, `kindHelp`), `conflictColumns` / `clashColumns`, `ConflictFilters`, `FieldComparisonCard` (portal value vs the other side's, differences first, "Show every field", card list on phones) and `ResolveCard` (choices behind `ConfirmDialog`, optional note).
- Backend: `TillData\Queries\SyncConflictList` / `SyncConflictDetail` (a row's members in the till's terms, secrets never shown), `TillData\Sync\OwnershipRules` (relayed / hubDrafted / derivedColumns of ownership.json).


## Added by module 2.7 (till health)

- `components/till-health/` (admin and tenant portal): `types.ts` (matches `HealthPresenter`, `TillHealthSummary`, `ShopsStatus`), `format.tsx` (`TillStateBadge`, `SyncStateBadge`, `ProblemPills`, `ago`, `londonDateTime`, `clockSkewText`), `BranchHealthStrip` + `TillHealthCell` (tenant page branch card), `TillHealthCard` (licence page), `ShopsStatusCard` (tenant dashboard).
- `components/admin/till-health/`: `tillHealthColumns`, `TillHealthRules`; `components/admin/dashboard/till-health-tile.tsx`.
- Backend: `TillHealth\Queries\CompanyHealth::for($companyId, $now)` (live health of one business, by branch and register id), `TillHealthSummary::compute(?$companyId)`, `TillHealthList`, `ShopsStatus`; `HealthPresenter` shapes rows for React.

## Added by module 3.2 (admin trading dashboard)

- `components/admin/dashboard/dashboard-tabs.tsx` — `DashboardTabs active="overview" | "trading"`: the Overview | Trading link tabs of the admin dashboard (hidden without `trading.view`).
- `components/admin/trading/`: `types.ts` (admin-only: `TradingFilters`, `TradingContext`, `LeaderRow`, `TradingData` extends the shared `SalesDashboardData`) and `TradingFiltersBar` + `queryOf()` (business picker, shop select; period/compare from the shared controls). Everything else moved to `components/shared/trading/` in 3.3 (below).
- Backend: `Admin\Queries\Trading\TradingDashboard` (`for($filters)` cached, `compute()`), `TradingContext`; `Admin\Data\TradingFilters` (implements `SalesWindow`); `Reporting\Queries\AdminTradingReport` (`topCompanies`, `topBranches`, `activity`; admin scope only). The page loads the figures as an Inertia deferred prop (`<Deferred data="trading">`).

## Added by module 3.3 (business dashboard; shared trading pieces)

- `components/shared/trading/` (used by admin 3.2 and tenant 3.3): `types.ts` (`SalesDashboardData`, `SalesKpis`, `SalesRange` with `today`, `DayPoint`, `HourPoint`, `TenderRow`, `VatRow`, `GroupRow`, `ProductRow`, `PeriodFilters`, `FreshnessInfo`/`ShopFreshness`, `TradingPeriod`, `TradingCompare`), `format.ts` (`money`, `moneyShort`, `moneyAxis`, `number`, `shortDay`, `weekday`, `dayRange`, `hourLabel`, `changeDelta`, `share`), `period-controls.tsx` (`PeriodSelect`, `CompareSelect`, `CustomRangeForm`), `TradingKpis`, `SalesTrendCard` / `HourlyPatternCard`, `TenderMixCard`, `VatCard`, `LeadersCard` (+ `actions`, `footer`; `salesDetail()`), `Freshness` (`info`, `generatedAt`; per-shop list in the tooltip when `info.shops` is given), `TradingSkeleton`.
- `CompareChart` (`shared/trading/compare-chart.tsx`): a point with `partial: true` (today, the hour now) is drawn "so far" — dashed segment to a hollow dot (area) or a pale dashed-outline bar — never as a drop. Curves `monotoneX`. Keep each Recharts series a direct child (no fragments).
- `AreaSparkline` `partialLast` and `KpiCard` `seriesPartial`: the last sparkline point as "so far". `TrendChart` lines are `monotoneX`.
- `components/app/dashboard/`: `types.ts` (matches `BusinessDashboard::compute()` + `ShopFreshness::for()`, `BusinessDashboardFilters::toArray()`, `BusinessContext::for()`), `BusinessFiltersBar` + `businessQuery()` (shop select = the portal switcher, locked for a one-shop user; till select), `OperationsTiles` (cash variance, low stock, tills online, orders to collect), `ShopsOrTillsCard`, `TopProductsCard`, `DepartmentsCard` (departments / categories), `StaffCard`, `BusinessBody`, `NoSalesYet`.
- `AppBranchSwitcher` honours the shared `branchLocked` prop (one-shop user).
- Backend: `Reporting\Dashboard\` — `SalesWindow` (interface), `DashboardKpis`, `DashboardSeries` (shared with 3.2), `BusinessDashboard` (`for()` cached 60 s, `compute()`), `BusinessDashboardFilters`, `BusinessContext`, `ShopFreshness`; `Reporting\Queries\OperationsReport` (`lowStock`, `cashVariance`, `ordersReady`; tenant scope); `Reporting\Enums\TradingPeriod` / `TradingCompare` (moved from Admin; `TradingCompare::window($scope)`); `Reporting\Support\TradingRange`. Tenancy: `CurrentCompany::restrictedBranchId()` (membership `branch_id`).

## Added by module 4.1 (portal users and roles)

- `components/app/users/`: `types.ts` (matches `PortalUsers\Data\PortalUsersPage::for()`, `EVERY_SHOP`, `formatDate`), `AccessDialog` (invite, or change role + shop; the shop picker is disabled for owners), `MembersPanel` (client search + role/status filters, table / phone cards, row actions locked for yourself and the last owner), `InvitationsPanel` (resend / cancel), `RoleMatrix` (read-only, from `CompanyRole::abilities()` via `PortalUsers\Support\RoleMatrix`).
- `pages/auth/accept-invitation.tsx`: the emailed link's states (`register`, `signIn`, `join`, `wrongAccount`, `expired`, `revoked`, `accepted`, `invalid`), from `PortalUsers\Data\InvitationLinkState`.
- Backend: `PortalUsers\Support\MemberAccess` (locked membership row, one-shop rule, "not yourself"), `InvitationMailer` (rotate token + signed 7-day link + `PortalInvitationMail`), `CompanyInvitation::findForLink()` (the only cross-company lookup, token-checked).

## Added by module 4.2 (products and catalogue, tenant portal)

- `components/app/products/fields.tsx`: `MoneyInput` (`places={2|4}`), `NumberField` (suffix), `OptionSelect` (with an
  optional "none" choice), `CheckRow` (checkbox card that reveals child fields when ticked), `formatMoney`, `marginPercent`.
- Backend: `Catalogue\Actions\SaveProduct` is the one way to write a product (form, CSV import, later AI tools); it keeps
  ids, writes only `ProductFields::EDITABLE`, and returns `SavedProduct` (created / changed keys).

## Added by module 4.3 (prices and promotions, tenant portal)

- `components/app/pricing/format.ts`: `formatDateTime` (London), `formatDay` (`Y-m-d`), `pounds`, `difference` (vs business
  price), status tone maps. `pricing-tabs.tsx`, `set-price-dialog.tsx`, `every-shop-dialog.tsx`, `price-history.tsx`.
- Backend: `Pricing\Actions\SetShopPrice` / `EndShopPrice` / `CancelScheduledPrice` / `SetEveryShopPrice`,
  `Pricing\Support\ShopPrices` (live winner per shop, row status), `Promotions\Actions\SavePromotion` / `EndPromotion`.

## Added by module 4.6 (sales and receipts, tenant portal)

- `DataTable` `footer` prop: replaces the page-number pagination in the card footer (keyset "Newer / Older" paging for huge lists).
- `components/app/sales/`: `types.ts` (matches `Sales\Queries\SaleList::for()` / `SaleReceipt::for()`), `format.tsx` (`saleKind`, `SaleKindPill`, `Amount` with a real minus sign, `DISCOUNT_SOURCE`, `LINE_FLAGS`, `actionLabel`), `SalesFilters` (date presets, shop / till / staff / tender, "More filters" for amount and customer), `ExportMenu` (CSV now or queued, recent exports, polls while one is building), `ReceiptLines`, `PaymentsCard`, `DetailsCard`, `LinkedCard`, `ActivityCard`.
- Backend: `Sales\Data\SaleFilters` (lenient query parsing, one-shop pin), `Sales\Queries\SaleSearch` (filters, keyset `page()` on (trading_day, id), `countUpTo()` capped count, `chunk()`), `Sales\Support\SaleNames` (batch id → name lookups), `Sales\Support\SalesCsv`, `Sales\Actions\QueueSalesExport` / `BuildSalesExport` + `BuildSalesExportJob` (`sales_exports`, private `local` disk, 7 days, owner-only download).

## Added by module 4.8 (reports, tenant portal)

- `components/app/reports/`: `types.ts` (matches `ReportResult::toArray()`, `ReportTable`, `Figures::of()`, `ReportController` props), `format.tsx` (`cellText`, `Cell` by column type — money, signedMoney red when short, qty, count, percent, datetime in London, status badge, alert flag; `reportQuery`, `reportUrl`), `ReportTableCard` (totals footer, paging), `ReportSummary` (StatCards with change vs compare), `SeriesChartCard` (bars with the compare window's bars), `HeatmapCard` (weekday × hour). Pages `app/reports/{index,show,print}` (print: no layout, A4 landscape print CSS).
- `BusinessFiltersBar` takes `showDates` / `showCompare` (default true) for reports of "now" or without compare.
- Backend: `Reporting\Reports\` — `ReportKind` (the reports, labels, views), `ReportOptions` (dashboard window + grouping, tab, page, export), `ReportBuilder` builders in `Builders/`, queries `SalesBreakdown`, `ProductBreakdown`, `StockLevels`, `ShiftLedger`, `ReportCsv`, `ReportHeading`; action `Reporting\Actions\BuildReport`. `OperationsReport::thresholdSql()` is public (the stock report uses the tile's rule).

## Added by module 5.4 (cash and Z, tenant portal)

- `components/app/cash/`: `types.ts` (matches `Cash\Queries\*` / `CashController`), `format.tsx` (`Variance` with a real minus sign, red short / green over; `Money`, `AlertFlag`, `MOVEMENT_TYPES`, `ShiftStatus`, `BANKING_STATUS`, `STAGE_LABELS`), `cash-page.tsx` (`CashPageLayout`: header, link to the 4.8 Shifts and Z report, section tabs; `CashFilters`: date presets, shop pinned for one-shop users, till), `shift-sections.tsx` (`TendersCard`, `CountsCard`, `MovementsCard`, `ZTendersTable`). Pages `app/cash/{shifts,shift,z-reports,z-report,banking,counts,cards,days,alerts}`.
- Backend: `Cash\Data\CashFilters` (lenient query, `scope()` shop/till, `during()` UTC window of London days, `onDays()` for `trading_date`), `Cash\Support\CashLookup` (pickers, id → name, money/ISO), `Cash\Support\ZTotals` (reads `ZReport.totalsJson`), queries `ShiftList`, `ShiftDetail`, `ZReportList`, `CashOffice`, `CardReconciliation`, `DayLockBoard`, `VarianceAlerts`. Ability `cash.view` (owner, manager, accountant).

## Added by module 5.2 (purchasing, tenant portal)

- `components/app/purchasing/`: `types.ts` (matches `Purchasing\Queries\PurchasingPage`, `OrderDetail`, `DocumentDetail`, `OrderForm`, `SupplierStatement`), `format.tsx` (`PurchasingStatus` + `PURCHASING_TONES` for every purchasing status, `PurchasingTabs`, `qty`, `cost` (4 dp), `documentHref`), `columns.tsx` (list columns per kind), `parts.tsx` (`ProductCell`, `Totals`, `LinkedCard`), `order-lines.tsx` (`LinesEditor`, `CatalogueCard`).
- Backend: `Purchasing\Queries\Lists\DocumentRows` (one list: filters, search incl. supplier name, stats; one class per kind), `Purchasing\Support\HeadOfficeOrders` (who owns an order, `reference` fallback), `ReorderSuggestion`, actions `SaveHeadOfficeOrder` / `ChangeHeadOfficeOrderStatus` (both through `TillData\Actions\DraftHeadOfficeOrder`).

## Added by module 7.1 (UI polish) — patterns

- **Page anatomy**: `PageHeader` (title, one-line description, actions right; `back` one level deep, `breadcrumbs` deeper; section `tabs`) → one filters bar → content cards (`SectionCard`, `ChartCard`, `DataTable`). Never add filters to the layout; never pass layout breadcrumbs and a header trail together.
- **Filters bar**: pass filters to `DataTable filters={…}`; a filter component's root should be `flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center` so it sits beside the search and still works standalone (see `CashFilters`, `StockFilters`, `AccountsFilters`). Controls are `h-9`; a one-shop user sees a locked shop pill.
- **Sidebar**: `ShellNavGroup.collapsible` (`'open'` / `'closed'` first-visit state, remembered in `localStorage`, forced open when it holds the current page and in icon mode). Tenant items carry an `ability` and are hidden without it (`components/app-sidebar.tsx`).
- **Charts**: `ChartLegend` (`chart-card.tsx`; markers `dot`, `dashed` compare, `hollow` today so far, `bar`, `bar-partial`) in a `ChartCard` `footer`; `ChartTooltipBox` (`trend-chart.tsx`) for every Recharts tooltip. Colours only via `ChartTone` / `toneVar`; compare series `muted`.
- **Tables**: headers are sentence case (sortable ones too). Select values are left-aligned after a leading icon.
- **Errors**: `pages/error.tsx` + `App\Http\Support\InertiaErrorPages` (403/404/500/503 when debug is off; JSON callers untouched; 419 → back with a toast).
- **Hero page headers (pass 3)**: `PageHeader` renders a slim hero band (`bg-brand-wash` + `BrandWaves`, like the dashboard `WelcomeBanner`): breadcrumbs/back, a white tile with the page icon in green, title + status, one-line description, actions right (wrapping under on phones); `tabs` sit under the band. The icon defaults to the page's sidebar nav icon via `usePageIcon()` / `pageIconFor(path)` (`components/shell/page-icon.ts`: admin by route name from `admin-nav.ts`, tenant by URL from `components/app-nav.ts`, `/settings` → profile icon); pass `icon` to override, `media` (avatar) replaces the tile on detail pages. Tenant nav items now live in `components/app-nav.ts` (`tenantNavGroups`, `tenantNav`, `currentTenantNavItem`). Dashboards keep `WelcomeBanner`; content cards and tables stay white.

## Added by two-factor sign-in and audit screens (7.1b)

- `RequireTwoFactor` middleware, alias `two-factor:<guard>`: put `two-factor:web` after `company` on any new portal route group (routes/app.php and routes/settings.php already have it).
- Tests: `actingAs()` marks the session as past two-factor (`Tests\TestCase::be`); call `$this->withoutTwoFactorPass()` first to test the real step, or `passTwoFactorFor($user, 'admin')` after `Auth::guard()->login()`.
- `components/shared/two-factor/`: `RecoveryCodesPanel` (one-time codes with copy/download/acknowledge), `RegenerateCodesDialog` (password → new codes from a JSON url), `TwoFactorStatus`, `CodeInput`.
- `components/shared/audit/`: `AuditLogView` (DataTable + filters + keyset pager + `AuditDrawer` with the before/after diff), `exportHref()`. Backend: `App\Domain\Audit\Queries\AuditLogList::for($request, AuditFilters::fromRequest(...), $companyId)` and `AuditCsv::download()`.

