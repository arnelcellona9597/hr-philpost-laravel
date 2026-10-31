# HR Philpost backend inventory for the Laravel + MySQL rebuild

Everything was read-only and nothing was changed. Paths are absolute. `CMS` means `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src` and is only used inside the absolute paths below. Items marked **[FLAG]** are non-obvious rules or likely defects. Section 16 lists what I could not verify.

## 1. Runtime and database

**Adapter**
- SQLite only, via `@payloadcms/db-sqlite` with the libsql driver.
- There are no Postgres or MySQL references anywhere in the repo.
- Config: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/payload.config.ts`.
- DB file is `DATA_DIR/hr.db`, with WAL on (`HR_SQLITE_WAL=false` disables it), a 30s busy timeout and `immediate` transactions.

**Schema**
- 43 tables in the latest snapshot: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/migrations/20261008_175844_hire_requirements_email.json`.
- 7 migrations, auto-run on production start.

**Process**
- One Node process serves both Next (Payload `/admin`, `/api`) and Astro, in `/home/infoa/Projects/Freelance/hr-philpost/server.mjs`. It runs under cPanel Passenger.
- Astro pages hold real backend logic in their frontmatter (`/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/**`).
- GraphQL is disabled. REST reads are capped at 200 rows and `pagination=false` is ignored (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/hooks/restLimits.ts`). `maxDepth` is 3.

**SQLite-specific code to replace**
- `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/dbLock.ts`: in-process async write lock, not needed in MySQL.
- `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/sqlite-busy-timeout.ts`: not needed in MySQL.
- `UPDATE ... RETURNING` for the 2FA attempt counter and queue claims (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/twofactor.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/mailer.ts`). MySQL has no RETURNING, so use `FOR UPDATE SKIP LOCKED` or conditional updates.
- `substr()` on date text and `json_extract` in `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/stats.ts`.

**Dates and money storage**
- Dates are ISO text. Date-only values are stored as `YYYY-MM-DDT12:00:00.000Z`, and "today" is always Asia/Manila (`/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/dates.ts`). Use MySQL `DATE`.
- Money is stored as `numeric`, handled as integer centavos in code. Use `DECIMAL(13,2)` or BIGINT centavos.

**Foreign keys**
- All relationships are `ON DELETE SET NULL`, even on NOT NULL columns.
- So a required-parent delete would hit a constraint error rather than being handled, if FKs are enforced (see section 16). Design explicit `RESTRICT` in MySQL.

**Env (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/env.ts`)**
- `PAYLOAD_SECRET` must be at least 32 chars outside dev/test. `DATA_DIR` and `SERVER_URL` are required in production.
- **[FLAG]** The local `.env` still has `SMTP_*` keys. No code reads them.

## 2. Roles and permissions

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/access.ts`, `/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/enums.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/middleware.ts`.

- Roles: `system-admin`, `hr-staff`.
- Statuses: `pending`, `approved`, `disabled`. Only `approved` counts as signed in.
- There is no employee self-service role. Employees are records and have no logins.
- HR Staff get full CRUD on all HR data.

System Admin only:
- Users CRUD beyond their own record, plus unlock.
- Changing a user's `email`, `role` or `status`.
- SMTP settings.
- Reading `queueKey`.
- Deleting certificates.
- Setting `media.isPublic`.
- Audit entries for `users` and `smtp-settings`.

Route guards: `/users` and `/settings/email-server`, re-checked inside the pages.

## 3. Collections (22)

All use `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/collections/`. "HR CRUD" means approved users only. All 22 pass through REST limits. Reusable field helpers are in `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/fields.ts`: text trims and collapses whitespace, blank becomes null, gov IDs are stored in canonical dashed form.

**employees** (`Employees.ts`), HR CRUD, audited, versions kept (max 50 per doc, `_employees_v`)
- Fields:
  - Personal: `fullName` (stored "LAST, First M. Ext."), `profilePicture`→media, last/first/middle/extension, gender (req), dateOfBirth, civilStatus, religion, bloodType, professionalEligibility, contactNumber, email, address.
  - Education: educationalAttainment, courses, yearLevelEarnedUnits, academicHonors.
  - Employment: `employeeId` (unique, req), classification (req: COS/Contractual/Regular), employmentStatus (req, default Active: Active/Resigned/Retired/Terminated), `station`→branches, region, position, natureOfWork, driverLicenseNumber, dateHired, lastDayOfService, remarks.
  - IDs and emergency: sss, pagibig, tin, philhealth, emergency contact fields.
- Virtual: `age`, `lengthOfService`.
- Rules: DOB not in the future, lastDay not before dateHired.
- Length of service ends at lastDay only if it is before today.
- Gov IDs are indexed but not unique. Formats (`/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/ids.ts`): SSS 10 digits, Pag-IBIG 12, PhilHealth 12, TIN 9/12/14.
- Delete is blocked if leave or compliance records exist. The message tells HR to change the status instead.
- **[FLAG]** The delete check omits payslips, certificates and applications.

**branches** (`Branches.ts`), HR CRUD, audited
- Fields: name (unique), code (unique, optional), address, region, contactNumber, notes.
- Delete is blocked while employees or applications reference it.

**applications** ("Onboarding Tracker", `Applications.ts`), HR CRUD, audited
- Fields:
  - applicationId (unique), applicantName, email, positionApplied, `station`→branches, replacement, dateApplied, startOjt (not before applied), applicationStatus.
  - Ten requirement selects (`reqPds`, `reqSpecimenSign`, `reqSwornDeclaration`, `reqTinVerification`, `reqPoliceClearance`, `reqMedicalLab`, `reqLandbankAccount`, `reqAssumptionOfDuty`, `reqDriverLicense`, `reqOrCr`), each Pending/Submitted/Verified/N/A.
  - dateSubmitted, computed `requirementsStatus` and `requirementsMissing`, remarks, `employee`→employees.
  - System-only: hiredAt, requirementsEmailSentAt, requirementsEmailTo.
- Only `Pending` counts as missing.
- `hiredAt` is set on the transition to Hired and nulled when no longer Hired.
- afterChange triggers the hire email (section 11).

**wellness-leaves** (`WellnessLeaves.ts`), HR CRUD, audited
- Fields: `employee`, name snapshots (always overwritten on save), dateFiling, inclusiveDateFrom/To, dateReceived (not before filing), status (default **Approved**), computed `days`, `year` (start year), `daysByYear` JSON, remarks.
- afterChange queues an email on create or status change.

**itr-submissions, sworn-declarations, pds-submissions, ipcr-ratings** (`compliance.ts`, one factory), HR CRUD, audited
- Common fields: `employee` (req), year (2000-2100, default current Manila year), name snapshots, dateSubmitted, dateReceived (not before submitted), remarks.
- Virtual `deadline` and `timeliness`, computed on read and never stored.
- Extra snapshots: sworn has station/jobTitle/tin, pds and ipcr have station/jobTitle, itr has extension.
- IPCR extras: ratingPeriod (Jan-Jun/Jul-Dec), periodFrom/To, rating (1-5, rounded to 3 decimals), computed adjectivalRating. Scale: ≥4.5 Outstanding, ≥3.5 Very Satisfactory, ≥2.5 Satisfactory, ≥1.5 Unsatisfactory, otherwise Poor.
- Unique: `(employee, year)`, plus `ratingPeriod` for IPCR.
- Delete of the parent employee is blocked.
- **[FLAG]** Snapshots are "editable", but on create they are always overwritten from the employee record. The check `employeeChanged` is true when there is no original doc. Non-blank Excel values on new rows are silently discarded, despite the "filled when blank" comment.

**payroll-periods, payslips** (`payroll.ts`), HR CRUD, audited. See section 10.
- Periods: name, code (unique), periodStart/End/payDate, status Draft/Released, releasedAt, releasedBy, notes. Delete is blocked while payslips exist.
- Payslips: `period`, `employee`, unique pair, snapshots (fullName, employeeCode, position, station, classification, tin, sss, philhealth, pagibig), `earnings[]` and `deductions[]` ({label ≤40, amount ≥0}, max 30 rows), grossPay, totalDeductions, netPay, remarks, correctedAfterRelease.

**email-templates** (`messaging.ts`), HR CRUD, audited
- Fields: name (unique), `key` (unique, marks system templates), category, subject, body.
- Unknown `{{placeholders}}` are rejected. Keyed templates cannot be deleted.

**messages** (`messaging.ts`), read/delete by staff, create/update nobody, not audited
- Fields: subject, body, category, audience, attachments[]→media, automatic, relatedCollection/relatedId (polymorphic text), sentBy, sentByName, status (queued/sending/sent/partial/failed), total/sent/failed/skipped.

**message-recipients** (`messaging.ts`), read only, not audited
- Fields: message, name, email, employee, user, subject, body, payslip, status, attempts, nextAttemptAt, error, sentAt.

**notifications** (`messaging.ts`), owner-only, only `readAt` writable
- Fields: user, title, body, link, readAt.

**holidays** (`Holidays.ts`), HR CRUD, audited
- Fields: date (unique), name, type (4 options).

**users** (`Users.ts`), see section 5.
- Fields: email, name (≤120), avatar→media, role, status, emailVerified, approvedAt, plus Payload auth columns and `users_sessions`.

**media** (`Media.ts`), see section 8.
- Fields: alt, isPublic, plus upload fields. Not audited.

**audit-logs** (`AuditLogs.ts`), see section 7.

**import-jobs** (`ImportJobs.ts`), read by staff, otherwise nobody
- Fields: fileName, module, status committed/failed, created/updated/unchanged, error, user, userName, archiveFile.

**login-challenges** (`LoginChallenges.ts`), no API access at all
- Fields: key, purpose login/invite, user, codeHash, tokenEnc, tokenExp, expiresAt, attempts, sendCount, lastSentAt, ip.
- **[FLAG]** For invites `codeHash` holds the literal `invite` or `reset`. Split this in Laravel.

**certificate-templates** (`certificates.ts`), HR CRUD, audited
- Fields: name, description, prefix (`^[A-Z0-9]{1,8}$`), paperSize (A4/Letter/Legal), font, margin, `blocks` JSON (max 40 blocks, sanitized, `/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/certificates.ts`), active, sortOrder, key.

**certificates** (`certificates.ts`), staff create/update, delete System Admin only, audited
- Fields: controlNumber, template, employee, purpose, issuedDate, snapshots (templateName, employeeName, `content` JSON, issuedBy, issuedByName), status Valid/Void, voidReason, voidedAt.
- Most fields are immutable after issue. Content is frozen at issue, so reprints are identical.
- Number format `PREFIX-YYYY-0001`, per prefix and year of issuedDate, computed as max+1 in code.
- Void requires a reason and is irreversible. PDF prints a VOID watermark.
- Inactive templates are refused.
- Text defaults: honorific and pronouns derive from gender, purpose defaults to "whatever legal purpose it may serve".
- Logic: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/certificates.ts`.

## 4. Globals (6)

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/globals/`. All write a diff to the audit log.

- **site-settings:** **public read**, staff update. companyName, tagline, logo, 6-colour palette, defaultTheme. Logo must be an image and is forced `isPublic=true`.
- **leave-settings:** annualAllowance (0-60, default 5), yearOverrides[{year, allowance}] with unique years.
- **notification-settings:** 5 auto-email switches, hourlyLimit (1-5000, default 100), replyTo, footer, `queueKey` (auto-generated, 18 random bytes hex), smtpLastError/At, smtpLastOkAt.
- **payslip-settings:** title, companyName, addressLines, showLogo, currencySymbol, earningItems and deductionItems (1-15, unique case-insensitive, default lists in the file), `show` group, prepared/certified names and titles, footerNote, paperSize. Item order drives grid and Excel columns.
- **smtp-settings:** System Admin only. See section 6.
- **requirement-deadlines:** itr, sworn, pds, ipcrJanJun, ipcrJulDec, each {month, day, nextYear}, plus overrides[{requirement, period, year, deadline}].
  - Rule: override beats usual, Feb 29 clamps to Feb 28.
  - Timeliness (`/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/deadlines.ts`): On time/Late by dateSubmitted, Overdue if unsubmitted after the deadline, otherwise Pending.
  - Without a deadline a submission is just "Submitted".
  - **[FLAG]** No deadlines exist on a fresh production install. Only the seed sets them.

## 5. Authentication

Files:
- `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/collections/Users.ts`
- `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/twofactor.ts`
- `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/accounts.ts`
- `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/lib/auth.ts`
- `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/login.astro`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/login/verify.astro`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/set-password.astro`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/profile.astro`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/users.astro`

**Login flow**
1. Password check runs Payload login, including lockout and status checks.
2. The session token is created but not given to the browser. It is AES-GCM encrypted into a challenge.
3. A 6-digit code is emailed directly, and the `hr-2fa` cookie (15 min, random key plus `next`) is set.
4. On a correct code, the challenge is atomically deleted and `payload-token` is set. `emailVerified` becomes true.

**2FA rules**
- Required on every sign-in, with no remembered devices.
- Code TTL is 10 minutes, HMAC-SHA256 keyed by the secret over `key:code`.
- 5 wrong attempts, counted atomically before compare, delete the challenge and revoke the session.
- Resend: 60s cooldown, max 5 sends per challenge. Each resend issues a new code and extends expiry, but attempts are not reset.
- One open challenge per user. "Use another account" revokes the pending session.
- Expired challenges are cleaned lazily at next login.
- **[FLAG]** If email fails or SMTP is unset, the code is written in plaintext to the server log and sign-in still proceeds, including in production. Invite and reset links are logged the same way. Decide if that break-glass behaviour stays.
- **[FLAG]** Wrong 2FA codes do not count toward account lockout.

**Sessions and lockout**
- HS256 JWT in the `payload-token` cookie (httpOnly, SameSite Lax, secure on https) plus a server-side `users_sessions` row.
- 12-hour absolute lifetime. Token refresh is blocked.
- Middleware re-checks `status === approved` per request.
- Lockout is 5 failed passwords for 15 minutes. Admin unlock is available, and setting a password via link also clears it.

**Blocked endpoints**
- `/api/users/{login,refresh-token,first-register,reset-password,forgot-password,unlock,verify}` are blocked in `server.mjs` after path normalisation.
- They are also blocked inside Payload with `context.trustedAuth`.

**Rate limit and CSRF**
- In-memory, 20 POSTs per IP and path per 15 min, 429 with `Retry-After: 900`. Paths: `/login`, `/login/verify`, `/forgot-password`, `/set-password`, `/profile`, `/users`, `/api/users*`.
- Client IP is the last `X-Forwarded-For` entry (`/home/infoa/Projects/Freelance/hr-philpost/server-security.mjs`).
- Astro middleware requires a same-origin Origin or Referer on non-GET requests.
- `safeNext` (`/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/redirect.ts`) prevents open redirects.

**Invites and resets**
- Public signup is disabled. `/register` redirects.
- Admin invite creates the user `approved` with an unusable random password.
- Link token is 256-bit, only SHA-256 is stored. 24h for invite, 1h for reset, single use, claimed by delete. A new link replaces the old one.
- Using a link sets `emailVerified`, unlocks, and logs out all sessions.
- If email is down, the link is shown to the admin to pass on.
- Forgot-password never reveals existence and only works for approved users.
- These emails bypass the queue, so links never appear in Messages.

**Password rules**
- Minimum 10 characters, must contain a letter and a digit.
- Self-change requires the current password, then logs out everywhere.
- Update without `context.passwordChange` is refused.

**Account safeguards**
- At least one approved System Admin must remain.
- Cannot delete your own account.
- Only admins change email, because a stolen session could otherwise redirect 2FA. Changing email resets `emailVerified`.
- Avatar and logo must be images, checked against the media record, so a scanned document cannot be attached by id.

**Password migration**
- Format is `pbkdf2-sha256-v1:<hex>`, PBKDF2-SHA256, 600,000 iterations, 32-byte key. The salt is the 64-char hex string used as UTF-8 text.
- Legacy: unprefixed hash, 25,000 iterations, 512-byte key.
- Laravel needs a custom verifier that rehashes on login, or send every user a reset link.

## 6. Email, SMTP storage and encryption

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/mailer.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/email/dbAdapter.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/email/layout.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/email/capture.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/crypto.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/globals/SmtpSettings.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/notify.ts`, `/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/merge.ts`.

**SMTP settings**
- Stored in the DB, never in files. Fields: host, port (default 465), security (ssl/starttls/none), username, fromAddress, fromName.
- The password is a virtual write-only field, encrypted to `passwordEnc`. `passwordSet` is the UI flag.
- `passwordEnc` is stripped from reads. Only a raw `db.findGlobal` sees it.
- TLS verification on, TLS 1.2 minimum, 15s/15s/30s timeouts. Config cached 60s.
- Save immediately verifies the connection. There is also a test-send action.
- **[FLAG]** A failed decrypt silently yields an empty password.

**Encryption**
- AES-256-GCM. Stored as base64 of `iv(12)|tag(16)|ciphertext`.
- Key is `SHA256("${PAYLOAD_SECRET}:hr:${purpose}")`. Purposes: `smtp-password`, `2fa-token`.
- Rotating the secret logs everyone out and voids the SMTP password. Plan to re-enter the password in Laravel, or decrypt during migration.

**Queue flow**
- `queueMessage` validates placeholders, expands the audience, renders per recipient, and saves Message plus one row per recipient.
- Text is frozen at queue time. **Payslip PDFs are rendered at send time.**
- Audience options: employee ids, filters (allActive, status, classification, branch), users, raw emails. Max 2000 recipients.
- Deduplicated by lower-cased email, **except payslip emails**, so spouses sharing an address both get theirs.
- Missing or invalid email creates a `skipped` row. All skipped makes the message `failed`.

**Sending**
- Batch 25, up to 8 batches per drain.
- Hourly budget is a rolling 60 minutes of `sent` rows.
- 3 attempts, retry after 2 then 4 minutes.
- Rows stuck in `sending` over 10 minutes are released.
- Status roll-up: pending with some sent → `sending`; pending with none sent → `queued`; no failures and some sent → `sent`; nothing sent → `failed`; otherwise `partial`.
- Failures record `smtpLastError` for a banner. Admins see full text, staff a generic message.
- **[FLAG]** 2FA, invite, reset and test mails use `sendNow` and do not count toward the hourly limit.

**Attachments and rendering**
- 5 MB total, PDF/PNG/JPG/WEBP, content sniffed.
- Body is plain text, escaped into a branded HTML template with a plain-text alternative.
- `{{leaveRemaining}}` is only computed if used in the template.
- **[FLAG]** Context fields can only be used by automatic or link-prefilled messages.

## 7. Audit logging

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/hooks/audit.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/audit.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/audit.astro`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/lib/activity.ts`.

- Fields: user, userName (or "System"), action (create/update/delete/import), collectionSlug, docId, docLabel, `changes` JSON.
- Update stores a `{field: {from, to}}` diff. Delete stores the **full record snapshot**.
- Excluded: timestamps, computed fields, auth internals.
- Immutable: create/update/delete all `nobody`. There is no retention policy.
- Writes run in the same transaction as the change.
- `context.skipAudit` skips hooks. System bookkeeping and import use it. Import writes one row-level `import` entry itself. Payroll import writes one summary entry.
- **[FLAG]** Not audited: logins, failed logins, lockouts, logouts, exports, PDF views, media, messages, notifications, sent mail.

## 8. File uploads and validation

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/collections/Media.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/lib/upload.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/avatar.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/files/[id].ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/excel/zipguard.ts`.

**Limits**
- Global 10 MB.
- Allowed MIME: png, jpeg, webp, gif, pdf. SVG is deliberately excluded.
- Employee photos 5 MB, logo 2 MB, avatar 2 MB, message attachments 5 MB.
- No image resizing (no `sharp`).

**Content sniffing**
- App paths use `file-type` magic bytes and save the detected MIME, never the browser's.
- Filename is sanitised (`[^\w.\-]+` replaced with `_`).
- Replacing an avatar deletes the old file.
- **[FLAG]** Employee photo replacement and removal do not delete the old media row.

**Serving**
- Files live outside the web root in `DATA_DIR/media`, served by `/files/:id` after an access check.
- Anonymous users only get `isPublic` files.
- `private, no-store` for private files, 5 minutes public otherwise.
- Sandbox CSP and nosniff are set.
- This exists for RA 10173 (Data Privacy Act) reasons, per code comments.

**Excel checks before parsing**
- `.xlsx` extension, ≤10 MB, ZIP magic.
- ZIP central directory check: ≤300 entries, ≤60 MB uncompressed total, ratio ≤200 for entries over 1 MiB. ZIP64 is rejected.
- Port this check verbatim.
- **[FLAG]** There is no XML or formula check beyond that. Formula cells use their cached result.

## 9. Import and export

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/excel/import.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/excel/export.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/excel/cells.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/imports.ts`, `/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/modules.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/data/`.

**Column contract**
- `modules.ts` is the single source of columns. The Excel header is the field label.

**Module order and upsert keys**

| Order | Module | Key |
|---|---|---|
| 1 | branches | name |
| 2 | holidays | date |
| 3 | employees | employeeId |
| 4 | applications | applicationId |
| 5 | leaves | employee + inclusiveDateFrom |
| 6 | itr, sworn, pds | employee + year |
| 7 | ipcr | employee + year + ratingPeriod |

**Three-stage flow**
1. Upload is staged in `DATA_DIR/imports/pending-<token>`, owned by that user, 1-hour TTL.
2. Static checks on every cell, then a **full rehearsal** of all writes in a rolled-back transaction, with up to 50 errors reported.
3. Confirm claims the file by atomic rename, re-validates against current data, and commits in one all-or-nothing transaction. The workbook is archived and an `import-jobs` row written.

**Cell rules**
- Blank clears a value, except "from employee" columns.
- Missing column leaves data untouched.
- Rows removed from the file are not deleted.
- Selects match case-insensitively to canonical values.
- Branch matches by name or code. Rows can reference branches or employees created in the same file.
- Leave rows apply chronologically so allowances are checked correctly.
- Duplicates in the file are errors.
- Limit is 10,000 rows per sheet.
- `README` sheet is ignored. Unknown sheets or columns give warnings, not errors.

**Dates**
- Real dates, serials (1900 leap bug and 1904 system handled), `YYYY-MM-DD`, or month-name text.
- `03/05/2024` is rejected as ambiguous.
- Leading-zero hint on numeric gov IDs.

**Export**
- All modules or one, respects filters, exports computed columns greyed out.
- Includes `Remaining Days` for leave, dropdown validations, a README sheet, and template downloads.

**[FLAG] Imports suppress notifications**
- An applicant set to Hired by Excel gets no requirements email.
- Leave filed by Excel sends no email.

**[FLAG] Payroll import is different**
- No row limit.
- No archive.
- No `import-jobs` row.
- One summary audit entry.

## 10. Payroll and payslips

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/collections/payroll.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/payroll.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/payroll-excel.ts`, `/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/money.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/payroll/[id]/index.astro`.

**There is no payroll engine.** No tax, SSS, PhilHealth, Pag-IBIG or attendance formulas exist, and HR enters every amount. The system only computes:
- gross = sum of earnings
- totalDeductions = sum of deductions
- net = gross − deductions

Rules:
- Calculated in integer centavos.
- Amounts are 0 to ₱1,000,000,000 with at most 2 decimals. Accepts `₱`, `PHP`, `P` prefixes and commas.
- Deductions greater than gross are rejected.

Workflow:
- **Generate:** zero-filled slips for Active employees without one, filterable by branch and classification, in one transaction.
- **Grid or Excel save:** validate all rows, then one transaction. Items on a slip outside the template are kept.
- **Excel:** blank cell means 0 for columns in the file. Missing columns stay unchanged.
- **Release:** blocked if already released, if there are no slips, or if any slip has zero gross. Sets status and queues PDF emails (subject to the setting and a checkbox).
- **Correction after release:** sets `correctedAfterRelease`. Resend prefixes the subject with "Corrected: " and clears the flag.
- There is no un-release.
- Delete all slips is refused once released (page-level rule only).
- PDF: A4, Letter or Half-Letter, hides zero-amount rows, DejaVu fonts for ₱, signatories, show/hide fields.

## 11. Leave and onboarding rules

**Leave** (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/leave.ts`, `/home/infoa/Projects/Freelance/hr-philpost/packages/shared/src/workdays.ts`)
- Working days are Mon-Fri minus holidays. Range is limited to about a year.
- Zero working days is rejected.
- Filings spanning a year boundary are charged to each year (`daysByYear`), and allowances are checked per year.
- Approved and Pending consume allowance and block overlaps. Disapproved and Cancelled do not.
- Remaining balance per filing is computed on read in date order.
- **[FLAG]** `days` and `daysByYear` are stored, so adding a holiday later does not recalculate old filings.
- **[FLAG]** Lowering an allowance makes old over-limit filings fail on their next edit.

**Onboarding requirements email** (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/notify.ts`)
- Trigger: becomes Hired or created Hired, or later gains an address while hired. Sent once.
- Requires setting `hiredRequirementsToApplicant` and an address: applicant email, else the linked employee's.
- Template `hired-requirements`; `{{requirementsList}}` is a bullet list of Pending items. Station falls back to company name, then "our office".
- Records `requirementsEmailSentAt` and `requirementsEmailTo` when **queued**, not delivered.
- Manual Send/Resend is available.
- Failures never block the save.
- **[FLAG]** Going Hired → not Hired → Hired sends nothing, because the sent timestamp is not cleared.

**Convert to employee** (`/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/employees/new.astro`)
- Pre-fills the form. The last word of the name becomes last name, and `dateHired` comes from `startOjt`.
- After saving, it links the application and sets Hired. This is a second write, not in one transaction.
- **[FLAG]** Default application IDs use `count + 1` (`/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/onboarding/new.astro`), which can collide after deletes.

**Compliance stats** (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/stats.ts`)
- Counts currently Active employees only.
- "Complied" means a record with dateSubmitted exists.
- **[FLAG]** For IPCR, one period is enough to count as complied, while timeliness counts per period.

## 12. Background work

**Email queue triggers**
- Timer every 30s in-process (`startQueueWorker`, disabled by `HR_QUEUE_WORKER=false`).
- 500ms after queueing, or 3s if inside a transaction.
- Manual "send now".
- Cron every 5 min: `GET /internal/queue` with header `X-Queue-Key`, timing-safe compare, returns `{sent, failed, remaining}` (`/home/infoa/Projects/Freelance/hr-philpost/apps/web/src/pages/internal/queue.ts`).

**Startup**
- `ensureDefaults` restores keyed templates, creates certificate templates on empty install, and generates `queueKey` (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/server/defaults.ts`).

**Cron schedule**
- Only the queue and nightly backup are documented (`/home/infoa/Projects/Freelance/hr-philpost/DEPLOYMENT.md`).
- There is **no** automated deadline reminder job. Reminders are a manual link that prefills the compose page.

Everything else is lazy. There is no purge of audit logs, archives or messages.

Laravel mapping: scheduler plus a database queue worker. On shared hosting, `schedule:run` from cron with `queue:work --stop-when-empty` is the closest match.

## 13. Seed data

Files: `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/seed/index.ts`, `/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/seed/samples.ts`.

- `pnpm seed` refuses to run in production unless `HR_SEED_FORCE=1`. It is deterministic and idempotent.
- Base data, only when there are no employees: 7 holidays for 2 years, 5 branches, 140 employees, leave, compliance, 28 applicants, 2 payroll periods.
- Samples fill gaps: 2 display-only HR accounts (`@sample.hr.local`), leave variety, deadlines, IPCR H2, applicant emails, 7 certificates (1 void), email history written as delivered, notifications, activity, import history.
- Production defaults to keep regardless: 5 keyed plus 2 sample email templates, 3 COE templates (`coe-active`, `coe-newly-hired`, `coe-separated`).

## 14. Scripts

Files in `/home/infoa/Projects/Freelance/hr-philpost/scripts/` unless noted.

- **`backup.mjs`:** `VACUUM INTO backups/hr-<stamp>.db`, integrity check, tar `media/`, rotate by mtime (`BACKUP_KEEP`, default 14). **[FLAG]** `DATA_DIR/imports` archives are not backed up.
- **create-admin** (`/home/infoa/Projects/Freelance/hr-philpost/apps/cms/src/cli/create-admin.ts`, bundled for release): creates the first System Admin, `--force` for another, `--reset-password <email>` resets any account and unlocks it, non-interactive with `--name`, `--email` and `HR_ADMIN_PASSWORD`. Port as an artisan command.
- **`check-host.sh`:** pre-deploy host checks.
- **`package-release.mjs`:** builds the tarball.
- **`e2e-server.mjs`:** e2e bootstrap.
- **`build-user-manual.mjs`, `shot.mjs`:** documentation only.

## 15. Other likely defects to resolve, not port

1. **Dead notification feature.** `notifyUsers` has no callers, so the bell only ever shows seeded data. `registrationToAdmins` does nothing.
2. **API bypass of UI rules.** `readOnly` is admin-UI only. Staff can use REST to set payroll `status`, `releasedAt`, `releasedBy`, or delete payslips in a released period, skipping the release checks. Enforce in Laravel policies or services.
3. **`generatePayslips` on Released periods.** There is no status guard.
4. **Payslip Excel export** lists only template labels, so extra items or renamed labels can make visible columns not add up to Gross.
5. **`{{leaveRemaining}}`** always uses the current year rather than the leave's year, and reads through raw drizzle during an open save, so the new filing may be excluded (unverified at runtime).
6. **Staff REST visibility.** `smtpLastError` is readable through the notification-settings global, though the UI hides it from staff.
7. **Stale code.** `forgotPassword` email config in `Users.ts` is unreachable. `/reset-password` only redirects old links. The mailer header comment omits invites and resets as direct sends.
8. **Certificate numbers** can be reused if the highest is deleted. Use a sequence table with a lock in MySQL.
9. **Disabled users with an open link.** `setPasswordWithLink` does not re-check status, though they still cannot sign in.

**Data migration note.** Files in `/home/infoa/Projects/Freelance/hr-philpost/data/` are untracked. `hr.db-wal` (4.3 MB, Oct 20) is newer than `hr.db` (Oct 9), so real data sits in the WAL. Export through a clean `VACUUM INTO` copy, never by copying `hr.db` alone.

## 16. Not verified

- Live row counts, since I did not open the database.
- Whether `PRAGMA foreign_keys` is on at runtime, so the outcome of deleting employees with payslips or messages with recipients is unconfirmed.
- Whether Payload sniffs content on direct `POST /api/media`. The app-level sniffing only covers Astro paths.
- Payload admin UI usage of `_employees_v` versions; I saw none in server code.