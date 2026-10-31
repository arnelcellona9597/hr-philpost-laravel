# Rebuild PhilPost HR in Laravel + MySQL (localhost first)

## Context
The current app is Payload CMS + Astro on **SQLite** (not Postgres): 22 collections, 6 settings groups, 67 routes, 86 integration tests and 19 Playwright tests. It will be rebuilt as one Laravel app, frontend and backend, on MySQL.

Decisions confirmed by the user:
- New folder and repo: `/home/infoa/Projects/Freelance/hr-philpost-laravel`. The old project stays untouched as the reference.
- Start fresh: no data migration. Default settings, templates and the System Admin only, plus a dev-only sample seeder.
- Localhost development only for now. No deployment in this plan, but the code must stay compatible with IONOS Web Hosting Plus.
- Laravel Boost / MCP is mandatory. **No app code is written until the Phase 0 gate passes.**

## Stack
- Latest Laravel (13.x, currently 13.39), PHP 8.5, MySQL 8.
- Blade + Livewire + Alpine + Tailwind 4, plus Chart.js. Assets are built with Vite locally, so the server needs no Node.
- Pest for tests. `phpoffice/phpspreadsheet` for Excel and `barryvdh/laravel-dompdf` for PDFs, both pure PHP.
- Database-backed sessions, cache and queue. No Redis or always-on workers, because shared hosting can't rely on them.

## Credentials
- Never store the supplied passwords in git, the plan, docs, or test fixtures.
- Local `.env` (gitignored) holds them for two one-time steps: `php artisan hr:create-admin` and the SMTP settings seed.
- SMTP: `smtp.ionos.com`, port 587, STARTTLS, username `info@phlposthr.com`. The password is stored encrypted in the database and edited on the Email Server page, as it is today.
- System Admin logs in with the `info@phlposthr.com` email through the normal app login. There is no separate admin panel: Blade pages replace Payload `/admin`.
- Recommend changing the password before launch: it was shared in chat and the mailbox and admin accounts use the same one.

## Phase 0: Environment and Boost gate (blocking)
1. Install PHP 8.5 CLI and extensions locally (currently 8.3.6); `sudo` may need the user. Keep 8.3 available for other projects.
2. Create the project with the latest Laravel, `git init`, and a local MySQL database and user.
3. Run `composer require laravel/boost --dev`, then `php artisan boost:install`, selecting Claude Code and the Livewire, Pest and Tailwind guidelines.
4. Check `.mcp.json` exists and `php artisan boost:mcp` starts.
5. **Stop and open a new Claude Code session in the new folder.** MCP servers load at session start, so this session can't get Boost tools. Approve the project MCP server.
6. Gate: run real Boost tool calls (`application-info`, `database-schema`, `search-docs`) and confirm the results. If any fail, stop and report. Do not code without it.

During all later phases, use Boost tools: `search-docs` before using framework APIs, plus `database-schema`, `tinker` and `last-error` while building.

## Phase 1: Foundation
- Migrations and Eloquent models for all collections. Use `DATE` columns, integer centavos for money, and explicit `RESTRICT` foreign keys.
- Settings tables for the 6 groups. Drop Payload internals, `_employees_v` versions and `dbLock`.
- Enums for roles, statuses and options; port shared logic from `packages/shared/src` (`dates`, `ids`, `money`, `workdays`, `deadlines`, `merge`, `redirect`, `modules`).
- Policies from `apps/cms/src/access.ts` and each collection's access rules. Asia/Manila timezone for "today".
- `App`, `Auth`, `Print` and `Base` layouts and 19 components, reusing tokens and classes from `apps/web/src/styles/global.css`.

## Phase 2: Auth and security
- Password, then emailed 6-digit code: 10-minute expiry, 5 attempts, 60s resend cooldown, max 5 sends.
- 12-hour absolute sessions, lockout (5 failures, 15 minutes), invites, reset links, password rules, last-admin safeguards.
- Security headers, rate limits, `safeNext`, private file serving, content-sniffed uploads. Ship HSTS and secure cookies off while `APP_URL` is `http://`.
- `php artisan hr:create-admin` and `--reset-password`. Port `apps/cms/src/cli/create-admin.ts`.

## Phase 3: Core HR
Employees (15-filter search, profile), Branches, Onboarding Tracker, Wellness Leave, Annual Requirements and deadlines, Profile, Notifications.

## Phase 4: Documents
Certificates, including frozen content, control numbers, void, PDF and the drag-and-drop template editor (Alpine port of the 679-line page). Payroll: periods, grid, release, payslip PDFs, Excel. No tax engine exists today, so none is added.

## Phase 5: Messaging, data and admin
- Mail queue from `apps/cms/src/server/mailer.ts`: 25-row batches, hourly limit, 3 attempts, frozen text. Run via `schedule:run` and `queue:work --stop-when-empty`.
- Excel import/export with stage, rehearsal and all-or-nothing commit, plus the zip-bomb guard from `apps/cms/src/server/excel/zipguard.ts`.
- Dashboard, audit log, Users, Settings, Email Server, Notification Settings, MySQL backup command.
- Keep the `/internal/queue` keyed endpoint as a fallback if IONOS cron is limited.

## Defects fixed rather than ported
- Payroll: API status bypass, generating on Released periods, delete rules enforced in the service layer.
- Certificate number reuse and application ID collisions: use locked sequence tables.
- Employee delete check extended to payslips, certificates and applications.
- Plaintext 2FA codes and links in logs: removed in production; replaced by an artisan command that issues a one-time code.
- Default requirement deadlines added on fresh install. Convert-to-employee runs in one transaction.
- Notification bell gets real events: failed mail and leave filings.
- Stored leave `days` staying unchanged after holiday edits remains as is, because it protects filed records.

## Local mail safety
Local default is the `log` mailer, and tests use `Mail::fake`. Real SMTP is used only for a deliberate test send to `info@phlposthr.com`. Sample seed data uses `@example.test` addresses so no real person can be emailed.

## Verification
- Port all 86 integration tests to Pest and the 19 E2E scenarios to Pest Browser or Playwright, and add one policy test per access rule.
- `php artisan test`, `vendor/bin/pint --test` and `npm run build` pass.
- Run the app at `http://localhost:8000`: sign in as System Admin with a real emailed code, then walk a staff account through every sidebar module.
- Check all 67 routes against a parity checklist; compare dashboards, PDFs and Excel files against the old app.
- Confirm no secrets are tracked: `git grep` for the password and `info@` password values.
- Pass a hosting check: no Redis, no Node at runtime, and `composer install --no-dev` works.

## Out of scope for now
IONOS deployment, SSL, and the user manual rebuild. A new `DEPLOYMENT.md` comes when deployment is scheduled. Before that, confirm the IONOS PHP 8.5 CLI path, MySQL version, cron availability, and document root set to `public/`.
