<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

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
| `/home` | 16 MB |

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
- Build locally and upload the result, because `/home` is too small for Composer's cache: run `composer install --no-dev --optimize-autoloader` and `npm run build`, then upload a single archive and unpack it over SSH.
- Point the document root at `public/`.
- Keep `.env` on the server only. `storage/` and `bootstrap/cache` must be writable.
- No Redis or always-on workers. Use cron for `schedule:run`, which runs `queue:work --stop-when-empty`.
- Keep backups off the server as well, and rotate the ones on it.

**Still to confirm on the server before deploying**

- PHP 8.5 CLI and web, which `composer.json` requires, with the `fileinfo`, `pdo_mysql`, `mbstring`, `zip` and `gd` extensions
- Web `memory_limit` of at least 256 MB for Excel imports and PDFs
- Disk quota of the webspace, which `df` doesn't show
- MySQL version and database size limit
- Cron availability and shortest interval

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
