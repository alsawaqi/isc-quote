# Quotation System Handover

Date: 2026-06-17  
Workspace: `C:\xampp\htdocs\quotation-system`

## Project Summary

This is an ISC quotation-to-cash and procurement tracking system built with Laravel 12, Vue 3, TypeScript, Vite, Tailwind CSS, PhpWord, DomPDF, and a custom JWT authentication flow.

The business flow is:

RFQ -> Quotation -> Buyer PO -> Supplier PO -> Follow-up -> Acknowledgement -> Shipping Documents -> Logistics/ETA -> Warehouse/Buyer Receipt -> Delivery Order -> Invoice -> Payment -> Close.

The app is an SPA. Laravel serves the Vue app through `routes/web.php`, and Vue owns the frontend routes. Backend JSON routes are under `routes/api.php`.

## Current Technical State

- Laravel API + Vue SPA routing is active.
- Custom JWT authentication is implemented with refresh tokens, token rotation, revocation, logout invalidation, and "keep me signed in".
- Login page is guest-only. Authenticated users are redirected to dashboard.
- Authenticated routes are protected in Vue and backend API.
- Role-based behavior exists for admin, salesperson, and follow-up users.
- The admin dashboard fetches live data from the database.
- Notifications are implemented for follow-up reminders.
- Sidebar is fixed while page body scrolls.
- Status and stage labels are cleaned up for display.
- Full audit timeline is admin-only.

## Important Files

- SPA entry view: `resources/views/app.blade.php`
- Vue entry: `resources/js/app.ts`
- Vue router: `resources/js/router.ts`
- Main shell/sidebar/topbar: `resources/js/components/AppShell.vue`
- Auth helpers: `resources/js/auth.ts`
- API routes: `routes/api.php`
- SPA catch-all route: `routes/web.php`
- Dashboard API: `app/Http/Controllers/DashboardController.php`
- Quotation API: `app/Http/Controllers/QuotationController.php`
- Supplier PO API: `app/Http/Controllers/SupplierPoController.php`
- Follow-up API: `app/Http/Controllers/FollowUpController.php`
- Admin trace/filter API: `app/Http/Controllers/AdminTraceController.php`
- Master data API: `app/Http/Controllers/Admin/MasterDataController.php`

## Main Features Already Built

### Admin And Master Data

- Countries CRUD
- Designations CRUD
- Companies CRUD
- Contacts CRUD
- Incoterms CRUD
- Manufacturers CRUD
- Suppliers CRUD
- Users and roles
- Direct user permissions with checkboxes
- Three fixed roles: admin, salesperson, follow-up
- Suppliers can optionally link to manufacturers
- Manufacturers are only country + name, not company/contact records
- Creating a salesperson can create their contact under the default internal company and link them as a supplier contact

### Quotation Flow

- `/quotations` lists quotations.
- `/quotations/create` is the wizard.
- Step 1 captures buyer company/contact, supplier defaults, RFQ, PR, closing date, quotation validity, payment terms, delivery estimate, currency, incoterm, delivery responsibility.
- Step 2 captures multiple products/items with manufacturer, title, buyer-facing description, manufacturer-facing extra details, quantity, UOM, unit price, total.
- Products are saved/linked.
- Step 3 captures standard terms: cancellation, scope of work, delivery term, warranty, force majeure, plus custom terms.
- Final review creates quotation revision/version.
- Word and PDF downloads are generated.
- Quotation detail has tab-like sections for viewing/editing quotation and creating buyer PO.
- Revisions are preserved and activity logs are recorded.

### Buyer PO Flow

- Buyer PO is created from quotation detail.
- Buyer PO marks the accepted/final quotation version.
- Stores PO number, date, value, and uploaded buyer PO file.

### Supplier PO Flow

- Sidebar has Supplier POs.
- Supplier PO listing page exists.
- Supplier PO create page exists separately.
- Supplier PO builder allows selecting buyer PO items after buyer PO stage.
- Items can be consolidated into one supplier PO when they share the linked manufacturer/supplier.
- Supplier PO can be edited.
- Supplier PO Word/PDF downloads are generated.
- Creating supplier PO creates follow-up items.

### Follow-Up Flow

- Follow-up dashboard shows reminders/due work.
- Follow-up item detail is step-by-step, not all panels on one page.
- Stages include acknowledgement, shipping documents, logistics/ETA, warehouse/buyer receipt, delivery order, invoice, payment, close.
- Follow-up reminders can be set with intervals.
- Comments are required per stage.
- Comments reset reminders based on saved interval.
- Shipping documents can be uploaded one by one.
- Shipping documents are audited with elapsed time between progress events.
- Required shipping docs include packing list, invoice, bill of lading, airway bill, certificate of origin.
- Packing list details are entered by the company; the other docs are uploaded.
- Logistics supports company-handled and buyer-handled delivery.
- Delivery order generation and signed copy upload exist.
- Invoice generation and VAT validation exist.
- Payment tracking supports partial/full payment.
- Job/follow-up item can close only after invoice is paid.

### Admin Trace And Filters

- Admin-only quotation trace page.
- Admin-only item trace page.
- Filters support quotation, buyer, supplier, manufacturer, status/follow-up status.
- Admin can open quotation context and see products, buyer PO, supplier PO, comments, status, timeline, and elapsed durations.

## Deployment State

The app was prepared for Hostinger deployment.

Deployment files added:

- `.env.hostinger.example`
- `.htaccess`
- `docs/HOSTINGER_DEPLOYMENT.md`
- `app/Console/Commands/CreateAdminUser.php`
- `app/Console/Commands/PrepareProductionStorage.php`

Important Hostinger notes:

- Production URL: `https://iscquote.com/`
- Database name/user are in `.env.hostinger.example`.
- The database password was provided by the user in the previous session but is intentionally not written in this handover. Ask the user or use the server `.env`.
- Hostinger may disable PHP `exec()` and `symlink()`.
- Do not use `php artisan storage:link` on Hostinger if it throws `Call to undefined function Illuminate\Filesystem\exec()`.
- Use this instead:

```bash
php artisan app:prepare-storage
```

Recommended first deployment commands:

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

Production seeding skips demo users. Create the first real admin using:

```bash
php artisan app:create-admin
```

## Verification Already Done

Most recent full verification after deployment prep:

```bash
php artisan test
npm run type-check
npm run build
php artisan route:cache
```

Result at that time:

- 96 PHP tests passed
- Vue type-check passed
- Vite production build passed
- Laravel route cache passed

After `app:prepare-storage` was added:

```bash
php artisan app:prepare-storage
php artisan test --filter=AppRoutingTest
npm run type-check
```

Result:

- command registered and ran successfully
- AppRoutingTest passed
- Vue type-check passed

## Current Caveats

- This folder is not currently a Git repository, so `git status`/`git diff` do not work here.
- `public/build` is generated locally by `npm run build`; upload it to Hostinger.
- Do not commit real `.env` or Hostinger credentials.
- Uploaded/generated documents are stored on Laravel's private local disk and downloaded through authenticated routes, so public storage symlink is not required.
- If Hostinger cannot run Composer, upload the `vendor` folder built locally with `composer install --no-dev --optimize-autoloader`.

## Useful Commands

Local development:

```bash
php artisan serve --host=127.0.0.1 --port=8001
npm run dev
```

Production build:

```bash
npm ci
npm run build
composer install --no-dev --optimize-autoloader
```

Verification:

```bash
php artisan test
npm run type-check
npm run build
```

Clear caches:

```bash
php artisan optimize:clear
```

Production cache:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Suggested Next Work

The next session can focus on "updating the system". Good next tasks are:

- Finish any Hostinger live-server issues after upload.
- Add missing production email settings if real outgoing emails are needed.
- Add export/report pages for admin trace results.
- Improve pagination/search on large trace and follow-up tables.
- Add database backup instructions for Hostinger.
- Add role/permission polish after real users are created.
- Add real company letterhead refinements to Word/PDF outputs if needed.
- Add production error logging/monitoring workflow.

## Prompt To Start A Fresh Codex Session

Use this in the next session:

```text
We are working on the ISC Quotation System in C:\xampp\htdocs\quotation-system. Please read docs/SESSION_HANDOVER.md first and continue from there. The project is Laravel 12 + Vue 3 + TypeScript SPA. We are now updating the system after building quotation, buyer PO, supplier PO, follow-up, logistics, delivery, invoice, payment, admin trace filters, and Hostinger deployment prep. Do not expose or commit production secrets.
```
