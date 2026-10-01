# Brand and UI standard: Switch & Save

> **Superseded for colours and layout by `docs/design/DESIGN-SYSTEM-v2.md` (owner-approved 2026-09-28).** Logo rules below still apply.
>
> **2026-10-01, pass 2: the light theme is the default for everyone** (dark is a user choice) and
> `docs/design/reference-light-final.webp` is the target look: white sidebar with the full logo at the top, light
> top bar with the mint wave wash, #F5F8FA canvas, white cards, green primary. The logo always appears complete
> (`BrandLogo`, object-contain, never cropped or stretched); `docs/design/logo-full-reference.png` shows it.

The owner's bar: **top-notch, professional, classic. Full functionality, no compromise.** Every screen is judged
against this file. If a screen would look at home in Stripe, Linear or Xero's dashboard, it passes.

> **Redesign in progress (paused).** Tokens, shared components and layouts were upgraded; see
> `docs/ui-redesign-status.md` and the "UI redesign additions" section of `docs/components.md`. Where this file
> and those differ, follow them: page titles come from `PageHeader` (text-2xl), lists use `DataTable` column
> `meta`, forms use `FormCard`/`FormSection`/`FormField`, detail pages use `SectionCard`/`DescriptionList`/`Timeline`.
> The full design-system rewrite of this file is step 7 of the remaining work.

## Brand

- Name: **Switch & Save** (the till product is SSPOS). Tagline: "Smart Solutions for Smart Businesses".
- Logo files in `public/images/brand/`: `switch-save-logo.png` (light), `switch-save-logo-dark.png` (dark),
  `switch-save-icon.png` (round "S" mark). Components: `BrandLogo` (full logo, sidebar top and auth pages),
  `AppLogoIcon` (mark: collapsed sidebar, phones, sidebar brand card). Never recolour, crop or stretch the logo.
- Colours (tokens in `resources/css/app.css`, use the Tailwind names, never raw hex):

| Role | Token / class | Use |
|---|---|---|
| Brand blue #015CFC | `primary`, `text-primary`, `bg-primary`, `ring` | Primary buttons, links, active nav, focus, chart series 1 |
| Brand green #01C301 | `success`, `brand-green` | Positive numbers, "active/paid/online" states, chart series 2. Never for primary buttons |
| Red | `destructive`, `bg-danger-soft` | Errors, overdue, suspended, destructive actions |
| Amber | `warning`, `bg-warning-soft` | Attention: trial ending, grace, low stock |
| Soft tints | `bg-success-soft`, `bg-warning-soft`, `bg-danger-soft`, `bg-info-soft` | Badge and banner backgrounds |
| Neutrals | `background` (soft grey canvas), `card` (white), `muted`, `border` | Everything else |

- Typography: Plus Jakarta Sans (Inter fallback). Page title `text-xl font-semibold tracking-tight`; section title `text-base font-semibold`;
  body `text-sm`; helper text `text-sm text-muted-foreground`. Numbers are tabular (automatic in tables; add
  `tabular-nums` elsewhere). Money always `£1,234.56`; dates `24 Sept 2026`, times `09:41` (Europe/London).
- Sentence case everywhere ("Add tenant", not "Add Tenant"). No exclamation marks, no "successfully", no "please".

## Layout

- Admin: `AdminLayout`. Tenant: `AppLayout`. Every page: `PageHeader` (title, one-line description, actions on the
  right) → content in white `Card`s on the grey canvas, `gap-6`, max width comfortable for reading (tables may be
  full width).
- Lists: `DataTable` with search, filters, sortable columns, pagination, row click to detail, an empty state that
  invites the first action, and a loading skeleton. Show counts ("48 tenants").
- Detail pages: header with name + `StatusBadge` + primary actions; key facts in a summary card grid; related data
  in tabs or stacked cards; an "Activity" card from the audit log.
- Forms: one column on phones, two on desktop for short fields; labels above inputs; helper text under; inline
  validation messages; primary action bottom-right ("Save changes"), secondary "Cancel"; disable the button and
  show a spinner while submitting; toast on success.
- Destructive or irreversible actions (suspend, revoke, delete, reset device) always use `ConfirmDialog` that
  names the thing and the consequence, e.g. "Suspend Khan Mini Mart? Their 3 tills will lock at the next check-in."

## Complete means complete

A module is not done until it has, where relevant: create, view, edit, archive/delete, search, filters, sorting,
pagination, empty states, loading states, error states, validation (server and client), confirmation for
destructive actions, success toasts, audit log entries, permission checks in UI (hide what you can't do) and
server (403), keyboard access, phone-width layout, light and dark mode, and tests for all of it.

## Quality checks before you report

- Look at every screen you built at desktop and 375px width, in light and dark mode.
- No placeholder text, lorem ipsum, TODO in UI, or dead buttons (a feature not built yet shows a muted "Soon" badge).
- No raw colour classes (`text-red-600`, `bg-emerald-50`…) — use the tokens above.
