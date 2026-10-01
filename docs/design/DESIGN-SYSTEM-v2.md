# Design system v2 (owner-approved, final 2026-09-28)

> **Pass 2 (2026-10-01): the LIGHT theme is the default and `docs/design/reference-light-final.webp` is the
> target.** It replaces the dark navy "chrome" frame described below. Everyone sees light unless they pick Dark or
> System in the account menu (saved under the `theme` localStorage key; the old auto-saved `appearance` key is
> ignored). Dark mode stays available and is kept in step through the same tokens.
>
> - **Sidebar**: white (`sidebar` #FFFFFF, `sidebar-border` #E6EBF0), full height on the left. At its top the FULL
>   logo (`BrandLogo`, `switch-save-logo.png` / `-dark.png`, 196px wide, object-contain, links to the dashboard)
>   with the area label under it ("Super admin console" / "Business portal"); the round mark when collapsed.
>   Section labels in small grey caps, outline icons, current page = soft green pill (`sidebar-active` #E3F4EC,
>   `sidebar-active-foreground` #006B43), green count badges, subtle grey "Soon" chips, the brand card at the bottom.
> - **Top bar** (64px, sticky, right of the sidebar): `bg-chrome-frame` (white into mint) + `BrandWaves`; hamburger,
>   rounded white search field with the ⌘K hint on one line, bell, help (chat bubble), divider, green avatar + name + role.
> - **Canvas** `#F5F8FA`; cards white, 1px `#E6EBF0` border, soft shadow, 14px radius (`--radius-card-value`).
> - **Primary** green `#097A4C` (white text 5.4:1); `muted-foreground` `#5D6B7E` (AA on the canvas and on cards).
> - **Dashboard hero**: `WelcomeBanner` = `bg-brand-wash` mint gradient + waves + sun icon tile. Admin dashboard adds
>   "Recent activity" and the "Business overview" status strip (real counts only). Charts stay monotone (never overshoot).
> - Sign-in screens (2026-10-01, "Enterprise sign-in"): left = deep brand panel (`bg-auth-panel`, navy → brand blue →
>   teal; staff console `bg-auth-panel-staff`, darker navy) with a fine grid, headline, a floating product preview built
>   from real UI cards (sample figures, aria-hidden, scales to the free height) and a trust row; right = elevated white
>   card (`shadow-auth`, 20px radius) on the dotted canvas with the full logo at its top (the only logo on phones).
>   Shared fields in `components/auth/auth-fields.tsx` (`IconInput`, `PasswordInput`, `AuthSubmit`, `AuthDivider`).

**Final reference: `docs/design/admin-dashboard-reference-2.webp`** (owner: "haan yahi final karo"). The earlier
`admin-dashboard-reference.webp` is kept only for comparison. This supersedes the colour rules in `docs/BRAND.md` and
the paused redesign in `docs/ui-redesign-status.md`. Bar: premium, calm, enterprise (Shopify admin / Xero / Stripe).

Agreed tweaks to the reference: no handwriting slogan in the welcome banner; gradients/glows very subtle (flat
surfaces preferred); no emoji — use an icon or nothing.

## Palette (light)

| Token | Value | Use |
|---|---|---|
| `chrome` | `#0F1C2C` | Full-height left sidebar **and** top bar (one dark frame); a barely visible green glow allowed at the top edge |
| `chrome-active` | `#103C38` | Active sidebar item background (text/icon white, subtle green left edge) |
| `chrome-foreground` | `#E6EBF0` / muted `#8FA0B3` | Sidebar text / group labels and icons |
| `background` | `#F4F8FA` | Page canvas |
| `card` | `#FFFFFF` | Cards, tables, dialogs; 1px `border` + very soft shadow, radius 12–14px |
| `border` | `#E4E8EC` | Hairlines, table rows |
| `foreground` | `#0F172A` | Headings, numbers |
| `muted-foreground` | `#64748B` | Labels, helper text, table headers |
| `primary` | `#007048` | Primary buttons, active segmented tab, links, positive chart |
| `primary-hover` | `#005E3C` | |
| `primary-soft` | `#E3F4EC` | Soft buttons, welcome banner tint, icon circles |
| `success` / soft | `#067A4A` / `#DCF5E8` | Active, healthy, positive delta |
| `info` / soft | `#2F5BD8` / `#E4ECFD` | Trial, informational, "License" pill, Active tills sparkline |
| `violet` / soft | `#7C3AED` / `#EFE7FD` | Trials sparkline, "Sync" pill |
| `warning` / soft | `#B45309` / `#FEF1D6` | Trial ending |
| `danger` / soft | `#D12E3B` / `#FDE1E3` | Overdue, alerts, negative delta |

Dark mode: canvas `#0B121B`, card `#121B26`, border `#223041`, chrome `#08111C`, primary `#1FAE78`.

## Layout

- **Frame**: dark top bar (64px) + dark full-height sidebar (232px) in `chrome`; content on the light canvas.
- **Top bar**: logo left; centred search "Search tenants, businesses, licence keys, or anything…" with
  ⌘K; right: Help, notifications bell with dot, avatar + name + role + menu.
- **Sidebar** (dark `chrome`): grouped with small uppercase muted labels — Overview;
  Customers (Customers, Leads, Tenants, Licences); Billing (Invoices, Plans); Operations (Till health, Devices,
  Sync & jobs); Communications (Emails, Templates); Settings pinned near the bottom, and the logo +
  tagline ("Smarter EPOS. Bigger growth.") at the very bottom. Active item: `chrome-active` background, white text,
  radius 8px. Count badges (e.g. Leads 3) as small green pills; a green dot for live status (Sync & jobs).
- **Welcome banner** (dashboard only): card with a very soft `primary-soft` tint, "Good afternoon, {name}" + one
  line, period picker and primary "New tenant" on the right.
- **Page header** (other pages): title (28px, bold, tight tracking), one-line subtitle; right: period picker
  ("Latest 12 weeks") + primary action (e.g. Export with menu).
- Content grid with 16px gaps, cards 12px radius.

## Components

- **KPI card**: icon in a soft circle + label + "…" menu; big number (32px bold); delta row (arrow + % in
  success/danger + "vs last month"); **sparkline** with soft area fill — each KPI its own tone
  (revenue green, tills blue, trials violet, overdue red); footer "£11,540 last month".
- **Chart card**: title + subtitle, segmented range control (12W / 6M / 1Y, active = primary filled), line with
  point markers and soft area; bar chart variant in primary; footer stat pill ("+24% vs 12 weeks ago").
- **Needs attention**: count badge; one row per item = toned label pill (Trial, Alert, Sync, License, Invoice) +
  one-line text + relative time + chevron.
- **Business overview**: two soft tiles (Total tenants, Total revenue 12w) with icon, number and delta.
- **Table card**: title + "View all →"; columns with muted headers; entity cell = initials avatar (soft grey) +
  name; numbers right-aligned tabular; status pills (soft background + strong text); row "…" menu.
- **System health**: rows (icon, name, green dot + "Healthy" + chevron), header pill "All systems operational".
- **Quick actions**: primary filled first button + outlined icon buttons (Issue licence, Create invoice, Send email).
- Typography: Inter; numbers tabular; weights 400/500/600/700; generous whitespace, nothing cramped.

## Rules

- Every admin page uses this shell and these components; tenant (business) panel uses the same system with its own
  navigation.
- Colour only through tokens; no raw hex in components.
- Phone: top bar collapses search to an icon, sidebar becomes a sheet, tables become card lists.
