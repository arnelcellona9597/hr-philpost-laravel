# Team handoff

Three developers work on this site, each with their own Claude Code session on a separate computer. Claude's own memory notes stay on each machine, so **this file and git are the shared memory**. Read it at the start of a session, update it at the end.

## Start of every session
1. `git fetch --all --prune`, then `git checkout develop && git pull`.
2. Read the "Current state" and "Log" sections below, then `git log --oneline -15` to see what the others did.
3. Branch from `develop`: `feature/<short-name>` (or `fix/`, `docs/`). Never work directly on `develop` or `main`.

## End of every session
1. Tests pass (`php artisan test --compact`) and `vendor/bin/pint --dirty --format agent` is clean.
2. Merge the branch into `develop`, merge `develop` into `main` (`--no-ff`), push both.
3. Add one entry to the **Log** (newest first): date, who, what changed, anything the others must do (migration, new command, new setting, deploy needed).
4. Update **Current state** if the live commit or an open item changed. Commit and push the update with the work.

## Rules everyone follows
- `main` is production-ready, `develop` is integration. Push `main` whenever `develop` is pushed.
- The live site holds **real employee data**. Never run seeders, wipes or data-changing commands on it without the whole team agreeing. Take `php8.5 artisan hr:backup` first, and use dry runs (`hr:import-cos`, `hr:apply-minimum-wages`) before `--commit`.
- Deploys are run by a human (SSH password is typed by that person, never given to Claude). Claude only prepares the commands. List changed paths with `git diff --name-only <live commit> main -- app bootstrap resources routes config database public`, ship them with `git archive`, add `public/build` (it is gitignored, so run `npm run build` first), then on the server run `php8.5 artisan migrate --force` and `optimize:clear`, `optimize`. Always call `php8.5` explicitly.
- Never commit secrets, `.env`, the real employee workbooks or any export containing personal data. Server host and credentials are shared privately, not in this repo.
- Add every audited model to `AuditTrail::AREAS`; new settings get a migration, not a seeder.
- Two people must not edit the same area at once: say in the Log or chat what you are starting.

## Current state
- Live site: https://phlposthr.com, deployed manually. **Live commit:** record the short hash here after each deploy (last known base: `0e8febb`, before the premium login and the changes in the log below).
- Open items on the live site: run the active-employee import and the minimum-wage command (dry run first), wage rates for Region XII and BARMM are missing, 27 people have TEMP employee IDs to replace, two employees lack a region.

## Log (newest first)
- **2026-11-15** — Employment history splits COS and Regular stints (new Classification column in profile, print and Word); birthday month/year filter on Employee Profiles; employee export honours chosen columns; formal requirements email (migration `update_hired_requirements_email_template`). Deploy needed: yes, includes a migration and `public/build`.
- **2026-11** — Premium login page with the Davao post office photo (`public/images/login-background.jpg`); favicon upload; sidebar logo fits landscape logos; Division/Unit filter; Branch → Division/Unit hierarchy; bulk print and Word bios; column chooser and rows per page; `hr:clear-records`, `hr:import-cos`, `hr:apply-minimum-wages` commands.
