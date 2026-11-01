<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## PhilPost HR

A Laravel 13, PHP 8.5 and MySQL 8 rebuild of the PhilPost HR system (the old Astro, Payload and SQLite app). Plan and handoff notes are in `docs/rebuild/`.

**What it does**

| Area | Notes |
|---|---|
| Employees | Records, search, Excel import and export, branches, and a dated employment history: one person can be hired and separated many times. The employee record shows the latest period; the profile lists every period with gaps and total service |
| Onboarding, leave, requirements | Applicant tracker, wellness leave, annual ITR, Sworn Declaration, PDS and IPCR records |
| Certificates | Numbered certificates from drag-and-drop templates, with PDF and print |
| Contracts | Contracts of Service for COS workers, issued singly or for a group. Drag-and-drop templates with a page break and a footer that repeats on every page. Officials and wage wording are set once in settings. Download as PDF or Word (.docx), or a ZIP of either. Issued contracts keep their text and can only be voided. A worker cannot hold two overlapping valid contracts |
| Wage orders and pay | Minimum wage per region from each wage order, applied in one step to the daily-paid employees it covers. Each employee keeps a pay history (basic rate plus premium, from starting rate through wage orders and salary increases) and a record of promotions and demotions, including changes of classification, with Excel import and export |
| Payroll | Periods, payslip grid, release, PDF and email |
| Remittances | Monthly SSS, Pag-IBIG (HDMF) and CMPEAI lists. A month starts from the previous one; finalize to lock it. Excel export and import in the office's sheet layouts, print, and a disbursement voucher (print and PDF) with the amount in words. Titles, employer details, signatories and voucher wording are edited in settings |
| Received Mails | Daily log of incoming mail with paste-from-Excel entry, filters, print, and Excel export and import in the monthly log layout |
| Messages, import and export, audit log | Internal messages and email queue, Excel modules, and a full audit trail |

**Setup**

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm install && npm run build
composer run dev
```

Demo data (1,000 fictional employees plus sample mail, remittances and contracts): `php artisan db:seed --class=DemoDataSeeder`. Do not run it against real data.

**Checks**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
```

Tests use a separate MySQL database (`hr_philpost_test`).

**Notes for operators**

- Real names, member numbers and addresses never belong in the repository. The officials who sign contracts and vouchers start blank and are filled in from the app's settings pages.
- The Region XI and Region XIII (Caraga) daily rates from the COS record load with `php artisan db:seed --class=RegionalWageSeeder`. It is safe to run again, and the wage order numbers are added on the Wage Orders page.
- Word export uses `phpoffice/phpword`, which needs the `zip`, `dom`, `gd` and `xml` PHP extensions.

## Live server (phlposthr.com)

Reference for deployment. No access details belong in this file, because this repository may be public.

**Measured or reported (November 2026)**

| Item | Value |
|---|---|
| Hosting | IONOS webspace, SFTP and SSH on port 22 |
| File limit | 262,144 files (5 used before deployment) |
| SSH shell memory | 6 GB shown by `top` |
| SSH shell CPU | AMD EPYC 9655P, 96 cores shown. This is the shared host, not a dedicated allocation |
| `/` | Read-only system image, 1.4 GB. Showing 100% is normal |
| `/tmp` | 128 MB |
| Home and webspace | `/home/www`, on shared network storage (295 TB pool). The real quota is in the IONOS panel |
| PHP | 5.6 to 8.6 installed. `php` is 8.3.36, so use `php8.5` explicitly (8.5.12, same as development) |
| `php8.5` CLI | `memory_limit` 512M, `upload_max_filesize` 128M, `post_max_size` 1024M, with `fileinfo`, `pdo_mysql`, `mbstring`, `zip` and `gd` |
| Tools | `git` yes, `composer` no, `crontab` no |

**File limit: not a problem.** A production install is about 8,500 files and folders, or 3% of the limit:

| Part | Files |
|---|---|
| `vendor/` from `composer install --no-dev` | 6,904 (plus 1,027 folders, 57 MB) |
| App code and config | 273 |
| Built assets in `public/build` | 11 |
| Compiled Blade views | About 210 |

Sessions, cache and the queue live in MySQL, so they add no files. Uploads add one file each, and PDFs and Excel exports are generated on demand, so normal use leaves over 250,000 files of room.

**Deployment rules**

- Never upload `node_modules` (8,515 files) or development packages. Node isn't needed on the server.
- Build locally and upload the result, because the server has no Composer: run `composer install --no-dev --optimize-autoloader` and `npm run build`, then upload a single archive and unpack it over SSH.
- Run every Artisan command as `php8.5 artisan ...`. Plain `php` is 8.3 and fails the app's PHP requirement.
- Run `php8.5 artisan optimize` after each deploy, since network storage makes loading many small files slower.
- Point the document root at `public/`.
- Keep `.env` on the server only. `storage/` and `bootstrap/cache` must be writable.
- No Redis or always-on workers. `crontab` isn't available over SSH, so schedule `schedule:run` in the IONOS panel. If that isn't possible, use the keyed `/internal/queue` endpoint.
- Keep backups off the server as well, and rotate the ones on it.

**Still to confirm on the server before deploying**

- The site's web PHP version is set to 8.5 in the IONOS panel
- The web `memory_limit` is at least 256 MB (the CLI value is confirmed; web PHP can differ)
- Webspace disk quota
- MySQL version and database size limit
- Cron jobs in the IONOS panel, and the shortest interval

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
