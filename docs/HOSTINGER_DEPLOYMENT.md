# Hostinger Deployment

Production URL: `https://iscquote.com/`

This app is a Laravel 12 API + Vue SPA. Laravel serves one Blade entry page, while Vue handles the in-app routes. The API routes remain under `/api`.

## 1. Server Requirements

- PHP 8.2 or newer.
- MySQL/MariaDB database.
- PHP extensions commonly required by Laravel, PhpWord, and DomPDF: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `fileinfo`, `zip`, `gd`, and `dom`.
- Writable `storage` and `bootstrap/cache` directories.

Hostinger currently documents Laravel 12 support and recommends PHP 8.2 or newer for Laravel hosting.

## 2. Build Locally

Run these before uploading when you do not have Node.js on the server:

```bash
npm ci
npm run build
```

Upload the generated `public/build` directory with the application.

If Composer is not available on the server, also prepare `vendor` locally:

```bash
composer install --no-dev --optimize-autoloader
```

If Composer is available on the server, it is cleaner to run that command there after upload instead.

## 3. Upload Layout

Preferred secure layout:

- Put the Laravel project outside `public_html`, for example `domains/iscquote.com/quotation-system`.
- Point the domain document root to `quotation-system/public` if Hostinger lets you change it.

Shared-host fallback layout:

- Upload the full project into `public_html`.
- Keep the root `.htaccess` from this repository. It rewrites traffic into Laravel's `public` directory.
- Keep `public/.htaccess` as-is. It sends Vue SPA routes to Laravel's front controller.

Do not upload local-only folders unless needed:

- `.git`
- `.qodo`
- `node_modules`
- `tests`
- local `.env`

## 4. Production Environment

Copy `.env.hostinger.example` to `.env` on the server.

Set these values carefully:

```dotenv
APP_KEY=
DB_PASSWORD="paste_hostinger_database_password_here"
```

Use the database password from Hostinger and keep it wrapped in double quotes because it contains special characters.

If Hostinger shows a different database host in hPanel, replace:

```dotenv
DB_HOST=localhost
```

Then generate the Laravel application key:

```bash
php artisan key:generate --force
```

Do not set `JWT_SECRET` unless you intentionally want a separate long random JWT secret. If it is absent, the app uses `APP_KEY`.

## 5. First Deployment Commands

From the project directory on the server:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\FoundationSeeder --force
php artisan app:create-admin
php artisan app:prepare-storage
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

The full `DatabaseSeeder` intentionally skips demo users in production. Use `app:create-admin` for the real first admin account.

Do not run `php artisan storage:link` on Hostinger shared hosting if PHP reports `Call to undefined function Illuminate\Filesystem\exec()`. Hostinger can disable both PHP `symlink()` and `exec()`. This project does not need the public storage symlink because uploaded and generated documents are downloaded through authenticated Laravel routes from private storage.

## 6. Permissions

Make these directories writable by PHP:

```bash
chmod -R 775 storage bootstrap/cache
```

The application writes generated Word/PDF documents and uploaded follow-up files under `storage/app/private`.

## 7. After Updates

For future deployments:

```bash
npm ci
npm run build
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan app:prepare-storage
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

If a page looks like it still has old assets, clear the browser cache and confirm the new `public/build/manifest.json` was uploaded.
