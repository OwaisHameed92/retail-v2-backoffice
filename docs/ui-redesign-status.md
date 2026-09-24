# UI redesign: status (paused)

The owner paused the redesign on 24 Sept 2026; it resumes at the end of the project. This file records what
changed, what is left, and the plan, so the next agent can pick it up without re-discovering anything.
`npm run lint`, `npx tsc --noEmit` and `npm run build` passed when it was paused. No backend, route, prop or
test changes were made.

## Critique of the screens before the redesign

- **Canvas and surfaces.** Inputs used `bg-background`, the grey canvas colour, so every form field looked
  greyed out or disabled on white cards. Cards had 8px corners and almost no depth; everything looked flat.
- **Shell.** The "inset" sidebar floated the whole page in a rounded box with a grey frame, which read as a
  template. The sidebar was one ungrouped list of 11 items, half of them "Soon", with no user card. The admin top
  bar had only search and a theme toggle; the tenant top bar had a disabled search field with a tooltip.
  No breadcrumbs anywhere in admin.
- **Page headers.** Each page hand-rolled its own title block (back link, `text-xl`, badges), so spacing and
  sizes drifted between plans, tenants and licences.
- **Tables.** Mixed-case muted headers, numbers left-aligned, no entity avatars, no sticky header, pagination
  outside the card, and phone layouts that hid columns with `hidden sm:table-cell` hacks inside cells.
  Admin users used a hand-built `<table>` with inline Edit/Deactivate text buttons.
- **Stat cards.** Tiny grey icon, no delta pill; on the admin and tenant dashboards the cards were built from
  raw `Card` + `CardTitle` with a `—`.
- **Empty states.** Plain grey circle. The admin dashboard said "arrive in module 1.9" (internal jargon in UI).
- **Auth.** Centred logo + form on a grey page; admin login looked identical to the customer login.
  The tenant "Remember me" checkbox was not wired to the form.
- **Tokens.** Raw palette classes (`text-green-600`, `bg-red-50`, `neutral-*`, `bg-black/80`) in auth pages,
  delete-user, appearance tabs, dialogs and the old header. Dark mode used dark text on a light-blue primary,
  so primary buttons and the impersonation banner looked washed out.

## What changed

### Theme (`resources/css/app.css`)
- Refined light and dark tokens; added `primary-hover`, `primary-soft`, `subtle` (table headers, inset panels),
  `border-strong`, `info`/`info-foreground`, `danger-foreground`, `overlay`, `brand-panel`, `sidebar-muted`.
- Elevation tokens → `shadow-card`, `shadow-raised`, `shadow-overlay`. Radius 8px for controls, 12px cards.
- Type tokens `text-2xs` (11px table headers) and `text-stat` (28px numbers). Inter features `cv11 ss01 cv05`,
  slight negative tracking on headings, reduced-motion support.
- Dark mode: filled buttons stay brand blue with white text; `.dark .text-primary` uses a lighter blue for
  links and text so contrast holds.

### UI primitives (`resources/js/components/ui`)
Button (36px default, 32px sm, 40px lg, soft shadow, 3px focus ring), Input/Select/new **Textarea** share
`fieldClasses` (white field, 40px, brand-blue focus ring, `aria-invalid` red), Card (12px, hairline, shadow),
Badge (new soft variants: success, warning, danger, info, neutral), Table (uppercase 11px headers on `bg-subtle`,
48px rows), Checkbox, Label, Tooltip (dark chip), Alert (new success/warning/info variants; destructive is a soft
red panel), Dialog/AlertDialog/Sheet (blurred token overlay, 12px, overlay shadow), Dropdown/Select menus,
Avatar, Breadcrumb, Sidebar (active item: brand tint + 3px blue marker; uppercase group labels).

### Shared components (`resources/js/components/shared`) — props stayed compatible
- Upgraded: **PageHeader** (breadcrumbs, back link, status, media, meta, tabs; title accepts a node),
  **StatCard** (icon in tinted circle, `tone`, delta pill, `chart` slot, `href`) + **StatGrid** + `KpiCard`
  alias, **StatusBadge** (`tone` override, more default statuses), **EmptyState** (`tone`, `size`, `bordered`),
  **DataTable** (column `meta.align` / `meta.mobile`, sticky header when the table fits, automatic phone card
  list, skeleton rows with avatar, pagination inside the card footer, clear-search button).
- New: **PageTabs**, **SectionCard**, **FormCard / FormSection / FormGrid / FormField**, **StickyFormBar**,
  **Timeline / TimelineChanges**, **DescriptionList**, **EntityCell / InitialsAvatar**, **RowActions**,
  **MobileCardList**, **Sparkline**. Documented in `docs/components.md`.

### Shells
- New `resources/js/components/shell/`: `SidebarNav` (grouped, "Soon" items), `SidebarBrand`, `Topbar`,
  `TopbarBreadcrumbs`, `SearchTrigger`, `HelpMenu` (keyboard shortcuts dialog), `NotificationsMenu`,
  `ThemeSubmenu`.
- **Admin**: grouped sidebar (Overview · Customers · Billing · Operations · Settings) from a new `group` field
  in `admin-nav.ts`, "Staff" tag, user card at the bottom; sticky top bar with breadcrumbs (default from the
  nav, pages can pass `breadcrumbs` to `AdminLayout`), the existing ⌘K search, help, notifications and account
  menu (theme moved into it); content in a centred `max-w-7xl` container (`width="narrow" | "wide"` available).
- **Tenant portal**: same shell; nav grouped Selling · Catalogue · Money · Team · Settings; branch and date
  filters in the top bar on xl, in a slim bar below it on smaller screens; impersonation banner and top bar stick
  together.
- **Auth**: split layout — flat brand-blue panel with a subtle grid-and-rings pattern, logo, tagline and three
  value points; form on the right. `variant="staff"` for the admin login ("Staff area", admin copy).

### Pages redone
Admin dashboard, plans (list, detail, create, edit — sectioned form + sticky action bar + timeline activity),
emails (log with tabs in the header, templates), admin users (list with entity cells, row menu, phone cards;
create/edit as sectioned forms), tenants (list, detail header/stats/tabs/details/branch cards/users/activity
timeline, create/edit with FormCard + sticky bar), licence detail (header, alerts, details card), tenant
dashboard, settings layout, all auth pages, account-on-hold (via layout).

Removed dead files: `app-header.tsx`, `breadcrumbs.tsx`, `app-logo.tsx`, `heading.tsx`, `nav-main.tsx`,
`appearance-dropdown.tsx` (all unused after the change).

## Checked

Before: every admin page, tenant detail, create forms, tenant dashboard and settings at desktop, and tenant
pages on a phone in dark mode. After: admin dashboard, plans list/detail/edit, emails log, admin users, tenant
dashboard (with impersonation banner) at 1440px light; plans list/detail at 375px dark; both login pages at
1440px light and 375px dark.

Most "after" checks used a static preview harness (scratchpad `harness/serve.py` + JSON fixtures): it serves
the real `public/build` assets with hand-written Inertia page props, so no login or server change was needed.
The in-app browser's admin session was lost during the work (starting and stopping "Login as customer" on a
copy of the database rotated the session, then the tab went back to :8000). **Someone needs to log in to
localhost:8000 again** to check the redesigned pages with live data.

Not yet checked visually after the change: tenants list/detail/create/edit, licences list/detail, email
templates page, admin users create/edit, settings pages, and most dark/phone combinations.

## Remaining work (in order)

1. Visual pass with live data on every page above, light/dark × desktop/phone; fix what it finds.
2. Licences: list columns (`licence-columns.tsx`: EntityCell, right-aligned dates, `meta.mobile`), filters,
   `tenant-licences-panel`, `licence-timeline` and `licence-activity` onto the shared `Timeline`,
   `licence-alerts-card`, key dialogs.
3. Tenants: `branch-dialog`, `register-dialog`, `user-dialog`, `reason-dialog`, `impersonate-dialog`,
   `till-count-picker`, `company-fields`/`branch-fields` polish; tenant "Billing" tab once 1.8 lands.
4. Email templates page and the email log sheet; plan feature picker selected state.
5. Leads (1.6) and Billing (1.8) screens built during the pause: bring onto PageHeader/DataTable meta/
   EntityCell/RowActions/SectionCard/Timeline where they are not already.
6. Settings pages (profile, password, appearance) onto FormSection/FormField; delete-user dialog copy.
7. Rewrite `docs/BRAND.md` into the full design system: tokens table, type scale, spacing, component usage
   with do/don't, page templates (list, detail, form, dashboard, settings), table + phone card pattern, and a
   pre-merge UI checklist. (Only a short pointer was added now.)
8. Loading skeletons that match detail and dashboard layouts (only tables and stat cards have them today).
9. A `/admin` command palette for navigation (pages and actions) alongside the existing record search.
