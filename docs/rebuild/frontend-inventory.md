# Frontend inventory: `/home/infoa/Projects/Freelance/hr-philpost`

This comes from static reading and grep only; I did not run the app or tests. Paths are relative to the repo root unless shown otherwise. Page paths are under `apps/web/src/pages/`.

## 1. Stack and counts

- **Stack:** Astro 7.3.5 SSR with `@astrojs/node`, Tailwind 4.3.3 through its Vite plugin, and Chart.js 4.5.1. The font is Inter Variable through `@fontsource-variable/inter`.
- **No client framework:** there is no React, Alpine or Vue. All client behaviour is vanilla TS/JS.
- **Data access:** pages call Payload's local API directly from `apps/cms` and `packages/shared`, with no HTTP API layer. Most reads and writes pass `overrideAccess: false`, so many permissions live in the CMS collections.

| Area | Count |
|---|---|
| Route files | 67 (53 `.astro`, 14 `.ts` endpoints; ~6,930 lines) |
| Layouts | 4 |
| Components | 19 (18 `.astro`, 1 types file) |
| `lib/` helpers | 15 |
| CSS | 1 file, 558 lines |
| Executable `<script>` blocks | 17 |

## 2. Roles and the common page pattern

**Roles:** there are two, `system-admin` and HR Staff.

**Default access:** any approved signed-in user unless listed here. Many pages set `canManage = Boolean(user)`, so that flag does not separate the roles.

**System Admin only:**
- `/users` and `/settings/email-server`, guarded in both middleware and the page.
- Rotating the cron key, and seeing it, on `/settings/notifications`.

**Restrictions enforced in the CMS layer, not in the pages** (taken from test names):
- Only System Admins delete certificates.
- Only System Admins make a file public.
- HR Staff cannot see account history in the audit log.
- HR Staff cannot read or create HR accounts, or read SMTP settings and the cron key.

**Common POST pattern:**
- Forms post to their own URL, using a hidden `action` field to pick the operation.
- A few forms use `?action=` in the URL instead, or `action={...}` to reach another route.
- Success follows POST, redirect, GET, with a flash cookie.
- Failure re-renders with status 400, field errors and the typed values.
- `lib/crud.ts` (`handleSave`, `handleDelete`) drives CRUD from field definitions in `packages/shared/src/modules.ts`.

## 3. Routes by feature

### Auth (public)

| Route | Purpose | POST |
|---|---|---|
| `/login` | Step 1: password check (`login.astro`) | Credentials, sets `hr-2fa` challenge cookie |
| `/login/verify` | Step 2: 6-digit emailed code | Verify, `cancel`, `resend` |
| `/forgot-password` | Request a reset link; never reveals if the email exists | Email |
| `/set-password?token=` | Invite or reset; min 10 chars with letters and digits | Password, confirm, token |
| `/reset-password` (`.ts`) | GET redirect to `/set-password` | None |
| `/register` (`.ts`) | Sign-up disabled; GET and POST redirect to `/login?notice=no-signup` | None |
| `/logout` (`.ts`) | Revokes the server session, clears the cookie | POST only |

`/login` notice codes are `password`, `restart`, `password-set`, `no-signup` and `expired`.

### Dashboard and account

- **`/` (`index.astro`, 510 lines):** GET filters for period, branch and classification. Shows KPIs, charts, compliance meters, recent hires, pending leave and an activity feed.
- **`/profile`:** POST `avatar`, `avatar-remove`, `name`, `password`.
- **`/notifications`:** POST `all` marks all read; marking one read follows its link via `safeNext`.

### Employees

- **`/employees`:** advanced search with 15 filter types, sort and page size 25/50/100 (`lib/search.ts`). Has "Export these results".
- **`/employees/new`:** create form, with `?fromApplication=` prefill for converting an applicant. Saves through `handleSave`.
- **`/employees/[id]`:** profile view, POST delete.
- **`/employees/[id]/edit`:** edit, plus photo upload or removal.

### Onboarding

- **`/onboarding`:** tracker table with inline requirement dropdowns and export.
- **`/onboarding/new`:** create with an auto-generated `APP-YYYY-NNN` ID.
- **`/onboarding/[id]`:** POST save, `?action=delete`, `?action=send-requirements`.
- **`/onboarding/requirement` (`.ts`):** POST JSON `{id, key, value}`, returns the updated status.

### Wellness Leave

- **`/leave`:** list and export.
- **`/leave/new`:** create; `/leave/[id]`: save and `?action=delete`.
- **`/leave/settings`:** POST `allowance`, `addHoliday`, `deleteHoliday`.
- **`/leave/preview` (`.ts`):** GET JSON allowance check and working-day count.

### Branches

- `/branches`, with a scope filter for active or all employees.
- `/branches/new`, `/branches/[id]` (POST delete), `/branches/[id]/edit`.

### Annual Requirements

`[type]` is one of `itr`, `sworn-declaration`, `pds`, `ipcr`.

- **`/requirements`:** yearly overview cards.
- **`/requirements/deadlines`:** POST `usual`, `overrides`.
- **`/requirements/[type]`:** list with on-time/late stats. POST `deadline`, `deadline-reset`, `generate` (blank rows for a year).
- **`/requirements/[type]/new`:** create.
- **`/requirements/[type]/[id]`:** save and `?action=delete`.

### Certificates

- **`/certificates`:** list with auto-submitting filters.
- **`/certificates/new`:** issue, with live preview.
- **`/certificates/preview` (`.ts`):** GET HTML fragment plus `X-Paper` and `X-Margin` headers.
- **`/certificates/[id]`:** view, POST `void`.
- **`/certificates/[id]/print`:** printable view.
- **`/certificates/[id]/pdf` (`.ts`):** PDF download.
- **`/certificates/templates`:** POST `create`, `duplicate`, `toggle`, `delete`.
- **`/certificates/templates/[id]` (679 lines):** drag-and-drop editor; POST saves `blocks` as JSON.

### Payroll

- **`/payroll`, `/payroll/new`, `/payroll/[id]/edit`:** period list and forms.
- **`/payroll/[id]`:** POST `generate`, `save` (amount grid), `release`, `delete-payslips`, `delete-period`.
- **`/payroll/[id]/import`:** POST `upload`, then preview, then `commit`.
- **`/payroll/[id]/export` (`.ts`):** xlsx download.
- **`/payroll/[id]/print`:** all payslips in a period.
- **`/payroll/payslips/[id]`:** POST `save`, `resend`, `delete`.
- **`/payroll/payslips/[id]/print`, `/pdf`:** printable view and inline PDF.
- **`/payroll/settings`:** payslip template.

### Messages

- **`/messages`:** log with filters.
- **`/messages/new`:** compose; multipart POST with attachments, template picker and placeholders.
- **`/messages/preview` (`.ts`):** POST form data, returns JSON including recipient and skipped counts.
- **`/messages/[id]`:** POST `process` (send now), `retry`.

### Import / Export

- **`/data`:** export and upload hub, plus import history.
- **`/data/import`:** POST-only flow. `upload` stages the file and previews, then `commit` applies it. Staged files are reached through a token.
- **`/data/export` (`.ts`):** `?module=all|<slug>&year=`, plus filters matching the source page.
- **`/data/template` (`.ts`):** blank xlsx templates.

### Administration

- **`/users` (admin):** POST `add`, `update`, `unlock`, `invite`, `reset`, `delete`.
- **`/settings`:** branding, palette, logo, default theme.
- **`/settings/notifications`:** save, `test`, `rotate` (admin).
- **`/settings/templates/[id]`:** email template edit and `delete`.
- **`/settings/email-server` (admin):** `save`, `test`.
- **`/audit`:** GET filters, 50 per page.

### System endpoints

- **`/files/[id]` (`.ts`):** reachable without a session, but Payload access rules apply. Staff see everything, visitors only `isPublic` files. Adds a sandbox CSP and `nosniff`; private files get `no-store`.
- **`/internal/queue` (`.ts`):** cron mail drain, authenticated by the `X-Queue-Key` header with a timing-safe compare.

## 4. Layouts (`apps/web/src/layouts/`)

- **`Base.astro`:** HTML shell, per-request `--brand-*` variables, noindex, favicon from the logo, inline theme script.
- **`App.astro` (300 lines):** sidebar, topbar, breadcrumbs, mail warning, denied and flash alerts, plus the global client script. Takes `title`, `heading`, `description` and `back` props, with an `actions` slot.
- **`Auth.astro`:** centred card with logo and tagline.
- **`Print.astro`:** forced light theme, dynamic `@page` size and margin, toolbar with `window.print()`.

## 5. Shared components (`apps/web/src/components/`)

- **Generic:** `Avatar`, `EmptyState`, `FormField`, `Icon` (inline Lucide-style paths, ~50), `ModuleForm`, `Pagination`, `SortHeader`, `StatCard`.
- **Shell:** `shell/Sidebar`, `shell/Topbar`, `shell/UserMenu`.
- **Domain:** `auth/CodeForm`, `certificates/CertificateSheet`, `leave/LeavePreview`, `payroll/Payslip`.
- **Charts:** `charts/Chart`, `charts/Sparkline`, `charts/SplitBars`, `charts/types.ts`.
- **Field kinds in `FormField`:** text, textarea, select, branch, employee, date, email, number, year, phone, govId. govId has format placeholders for SSS, Pag-IBIG, PhilHealth and TIN.

`Chart` renders bar, line, area and doughnut, and includes an HTML legend and a table view for accessibility.

**Helpers in `lib/`:** `activity`, `auth`, `choices`, `compose`, `crud`, `deadlines`, `errors`, `flash`, `format`, `forms`, `nav`, `payload`, `payroll`, `search`, `upload`.

## 6. CSS and design tokens (`apps/web/src/styles/global.css`)

- **Dark mode:** a custom variant keyed on `[data-theme='dark']`. Dark values override the neutrals, the derived colours, shadows and chart tokens.
- **Brand tokens:** `--brand-primary #00539b`, `secondary #777777`, `accent #ffc72c`, `success #15803d`, `warning #ffc72c`, `danger #c8102e`, and `on-primary`. All are overridable per request from Settings; derived shades use `color-mix(in oklab, ...)`.
- **Neutrals:** `--bg`, `--surface` 1 to 3, `--border`, `--border-strong`, `--fg`, `--fg-2`, `--muted`.
- **Elevation and shell:** `--elev-1`, `--elev-2`, `--elev-pop`; `--sidebar-w 16rem`, collapsed `4.5rem`, `--topbar-h 4rem` (compact `2.75rem`).
- **Charts:** `--chart-grid`, `--chart-axis`, `--chart-ink`, and `--series-1` to `4`. These are a fixed colourblind-safe palette, independent of the brand.
- **Tailwind mapping:** `@theme inline` exposes tokens as utilities such as `bg-surface`, `text-fg-2` and `shadow-elev-1`.
- **Component classes:** `.card`, `.card-hover`, `.section-title`, `.eyebrow`, `.btn-primary/secondary/danger/ghost/icon/sm`, `.label`, `.input`, `.field-error`, `.field-help`, `.table`, `.badge-*`, `.tabular`, `.kbd`.
- **Shell CSS:** `#shell[data-sidebar|data-side|data-header]`, `.nav-link`, `.menu-panel`, `.menu-item`, `.pref-switch`, `#nav-tip`. Respects `prefers-reduced-motion`.

## 7. Client-side behaviours

- **Theme:**
  - The `hr-theme` cookie overrides the Settings default, which can be `system`.
  - In `system` mode it follows the device live.
  - Changes dispatch an `hr:theme` event so charts re-render.
- **Layout preferences:** cookies `hr-sidebar`, `hr-sidebar-side` and `hr-header` (1 year), rendered server-side to avoid a flash.
- **Shell (`App.astro`):**
  - Mobile drawer with focus trap, collapsed-sidebar tooltips, and a keyboard-navigable account menu.
  - `/` focuses search.
  - Session-expiry redirect, checked every 30s and on tab visibility.
  - `form[data-dirty-guard]` (10 uses) and `form[data-confirm]` (23 uses).
- **Charts:** canvases read a JSON spec from `data-chart`, and read colours from CSS tokens.
- **Tables:** all server-rendered, with URL-based sort and pagination, and clickable `tr[data-href]` rows. `data-autosubmit` auto-submits filters. There is no client table library.
- **Exports:** Excel is generated server-side by `exceljs`; the browser just follows download links.
- **PDF:** generated server-side with `pdf-lib` and fontkit in `apps/cms/src/server/certificates.ts` and `payroll.ts`. The print pages use browser print. There is no client PDF generation.
- **Live previews:**
  - Certificates: fetch with stale-response protection and scale-to-fit.
  - Leave: JSON check.
  - Messages: `srcdoc` iframe plus a skipped-recipient list.
  - Payroll grid: live totals computed in cents.
  - Settings: colour swatches and presets, with a contrast calculation.
  - Profile: avatar preview and size check.
- **Other forms:**
  - `CodeForm`: digits only, auto-submits at 6 digits, resend countdown.
  - Employee dropdowns: type-to-filter.
  - Messages: placeholder insertion at the cursor.
  - Onboarding: inline `fetch` save with rollback on error.
- **Certificate template editor:** pointer-event drag and drop (mouse, pen, touch), a block palette, an inspector, placeholder chips and keyboard actions. Layout is saved as JSON. This is the largest single piece to port.

## 8. Middleware (`apps/web/src/middleware.ts`)

Order of checks:

1. **CSRF:** non-GET/HEAD requests need an `Origin` or `Referer` matching `SERVER_URL`'s host in production, otherwise the `Host` header. A missing header gets 403.
2. **Static bypass:** `/_astro/` and `/favicon`.
3. **Session:** the `payload-token` cookie is validated through Payload. It must belong to the `users` collection with status `approved`; otherwise the cookie is cleared.
4. **Per-request locals:** site settings, theme, unread count, and a mail warning. The warning shows full error text to admins only.
5. **Public routes:** the seven listed paths and `/files/*`.
6. **Unauthenticated access:** POST gets a 401 text response; GET redirects to `/login?next=`.
7. **Signed-in users:** on `/login` or `/register`, redirect to `/`.
8. **Role rules:** `/users` and `/settings/email-server` redirect to `/?denied=1`.
9. **Caching:** signed-in responses get `Cache-Control: private, no-store`.

Other cookies: `hr-flash` (60s) and `hr-2fa` (15 min).

## 9. `server.mjs` and `server-security.mjs`

**Topology:** one Node process. `/admin`, `/api` and `/_next` go to Next/Payload; everything else goes to Astro. `passenger.cjs` is a thin startup wrapper for Passenger.

**Headers on all responses:**
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: same-origin`
- `X-Frame-Options: SAMEORIGIN`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `Cross-Origin-Opener-Policy: same-origin`
- HSTS for 1 year, only when `SERVER_URL` is https

**CSP (Astro responses only):** `default-src 'self'`, with `'unsafe-inline'` for scripts and styles, `data:`/`blob:` images, `object-src 'none'`, and `base-uri`, `form-action` and `frame-ancestors` all `'self'`.

**Rate limit:**
- 20 POSTs per IP and path per 15 minutes, in memory, returning 429 with `Retry-After: 900`.
- Tracks at most 10,000 keys. `HR_AUTH_RATE_LIMIT` overrides the limit.
- Covers `/login`, `/login/verify`, `/forgot-password`, `/set-password`, `/profile`, `/users`, `/api/users`, `/api/users/forgot-password`.

**Blocked with 403 JSON:** `/api/users/` + `login`, `refresh-token`, `first-register`, `reset-password`, `forgot-password`, `unlock`, `verify`.

**Redirects:**
- `/admin/login` to `/login?next=/admin`
- `/admin/create-first-user` to `/register`
- `/admin/forgot` and `/admin/reset/*` to `/forgot-password`

**Helpers:**
- `normalizePath` percent-decodes, collapses slashes, strips the trailing slash and lower-cases. Malformed encoding or control characters and backslashes return 400.
- `clientIp` uses the last `X-Forwarded-For` entry.

**Size limits:**
- 12 MB request body (`astro.config.mjs`)
- 10 MB Excel
- 5 MB images and 5 MB total attachments
- 2 MB logo

Uploads are checked by magic bytes.

## 10. Tests

There is no `apps/web/tests` directory.

**E2E: `tests/e2e/hr.spec.ts`** (Playwright, ~19 tests, Chromium, serial)
- Runs one ordered story against the production build, using `scripts/e2e-server.mjs` with a throwaway database and captured email.
- Covers first-admin login with 2FA, invites and staff restrictions, dashboard, security, SMTP settings, employees and search, leave.
- Also covers branding and dark mode, Excel round trip, payroll, messages, avatars, certificates, requirements, onboarding email, app shell, phone layout, password change.

**Integration: `apps/cms/tests/int/`** (Vitest, ~86 tests, backend logic)

| File | Tests | Covers |
|---|---|---|
| `core.int.spec.ts` | 24 | Accounts, employees, leave, requirements, onboarding |
| `phase2.int.spec.ts` | 17 | 2FA, invites, mail queue, payroll |
| `security.int.spec.ts` | 11 | SMTP encryption, protected fields, REST caps, zip bomb |
| `certificates.int.spec.ts` | 9 | Numbering, frozen content, void, PDF |
| `excel.int.spec.ts` | 9 | Round trip, all-or-nothing import, rollback |
| `hire-email.int.spec.ts` | 6 | Requirements email rules |
| `avatar.int.spec.ts` | 5 | Profile pictures |
| `deadlines.int.spec.ts` | 5 | On-time and late logic, Excel columns |

## 11. User manual (`docs/`)

**Files:**
- `docs/user-manual/manual.html` (712 lines): the manual's source, edited directly
- `docs/user-manual/screenshots/`: 45 PNGs
- `docs/User-Manual.pdf`: 9.8 MB

**Contents (16 chapters):** Welcome, Getting started, Finding your way around, Dashboard, Employees, Onboarding Tracker, Wellness Leave, Branches, Annual Requirements, Certificates, Payroll, Messages & Notifications, Import/Export, Administration, My account, Quick help & glossary.

**Generation (`scripts/build-user-manual.mjs`):**
1. Run `pnpm build`, then `pnpm manual`. `pnpm manual --pdf-only` reprints from existing screenshots.
2. The script starts `scripts/e2e-server.mjs` on port 4410 with seeded sample data.
3. Playwright takes screenshots at 1280x800 and 1.5x scale, adding numbered highlight marks, plus phone views.
4. Chromium prints `manual.html` to an A4 PDF with a footer.

The screenshot script depends on current routes, selectors and the seed data.

## 12. Points to preserve or decide in the Laravel rebuild

- **Hidden permissions:** Astro pages expose few role checks, so real access rules must come from the Payload collections. Port these to Laravel policies, and keep a test for each rule.
- **Dev versus production:** headers, rate limits and blocked paths exist only in `server.mjs`, so they do not run under `pnpm dev`.
- **Rate limit side effect:** the limit counts every POST to `/users` and `/profile`, so normal admin work can hit 20 per 15 minutes. Decide whether to keep this.
- **CSRF change:** Laravel tokens replace the Origin/Referer check. The two `fetch` POSTs (`/onboarding/requirement`, `/messages/preview`) will need the token, and `/internal/queue` likely becomes the scheduler.
- **CSP:** `'unsafe-inline'` is currently required by the inline scripts. A rebuild is a chance to remove it.
- **Payload Admin:** the sidebar link to `/admin` shows for all roles and goes away with Payload, along with the `/admin` redirects and `/api` blocks. Any admin-only workflows there would need replacing, and I did not inventory them.
- **Excel import token flow:** upload, preview and commit is a two-step staged process with an all-or-nothing commit, which should be kept.