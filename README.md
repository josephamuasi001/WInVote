# WInVote

WInVote is an original multi-event voting and event-management platform concept. The project contains the responsive **Phase 1 frontend**, the approved **database schema and development seed**, and **Phase 2 backend modules** for authentication, organizer-owned event management, nominations, voting and public results.

## Run locally

Open `index.html` directly in a modern browser for the static preview. Preview-only forms, voting, nominations, results and workspace dashboards use illustrative local interactions; they do not save data. Workspace previews contain sample data and are not protected, so do not use them for real administration or enter real information. When served over HTTP, static admin pages redirect to login and static organizer pages redirect to the protected event-management route. To use the PHP backend, run Apache and MariaDB in XAMPP, complete the setup below, and serve this folder through a PHP-enabled web root. PHP 8.4 or later with PDO MySQL and mbstring is required.

If Apache is already serving another virtual host, `http://localhost/WInVote/` may resolve inside that host's document root rather than `C:\xampp\htdocs`. Either configure a local virtual host for this folder or, for development only, run the PHP built-in server from this folder:

```powershell
php -S 127.0.0.1:8089 -t C:\xampp\htdocs\WInVote C:\xampp\htdocs\WInVote\router.php
```

Then open `http://127.0.0.1:8089/`. The built-in server is for local development and is not a production deployment server.

### Create the development database

With XAMPP's MariaDB service running, open PowerShell in the project folder and run:

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -u root -e "SOURCE C:/xampp/htdocs/WInVote/sql/001_schema.sql; SOURCE C:/xampp/htdocs/WInVote/sql/002_seed_development.sql;"
Copy-Item .env.example .env
```

The scripts create the `winvote_dev` database, its InnoDB tables and illustrative development records. Change the local path in the `SOURCE` commands if you placed this folder elsewhere. Use an appropriately secured database account instead of XAMPP's default root account outside local development.

Existing databases created before image uploads need the additive image-column migration. From the project folder run `php scripts\migrate_image_uploads.php` once. Uploaded images are limited to JPEG, PNG, or WebP files up to 2 MB, kept under the protected `storage` directory, and referenced by generated storage keys in MySQL. The PHP `getimagesize` support is required for upload validation.

The local `.env` file is ignored by Git. Set `DB_USERNAME` and `DB_PASSWORD` in it to match your local MariaDB account. Apache must have PHP enabled. The `.htaccess` files deny direct web access to configuration, source, SQL, test files, and uploaded images.

## Pages

- Public experience: homepage, event discovery and details, contestant profile, voting, results, nominations, About, support and authentication screens.
- Organizer workspace: dashboard, events, event creation/editing, event details, categories, contestants, nominations, votes, transactions, results, reports, settings and profile.
- Platform administrator workspace: dashboard, organizers, events, users, contestants, nominations, transactions, payments, revenue, withdrawals, reports, support, settings, audit logs and profile.

## Frontend structure

```text
assets/
  css/app.css        Shared visual system and responsive layouts
  js/app.js          Page rendering, preview data and local interactions
  js/voting.js       Ballot selection controls
organizer/           Organizer dashboard page entry points
admin/               Platform administrator page entry points
sql/
  001_schema.sql     MySQL/MariaDB schema
  002_seed_development.sql  Idempotent illustrative development seed
router.php           Local PHP server access rules for private files and previews
config/              Application configuration and PDO connection
src/
  Helpers/           CSRF utilities
  Middleware/        Authentication and role/ownership checks
  Services/          Authentication, event, organizer analytics, category, contestant, nomination, voting and payment-simulation logic
  bootstrap.php      Environment, database and session initialization
tests/               Database-backed smoke checks
auth.php             Registration, login and logout endpoints
index.php            Database-backed public homepage and event statistics
events.php           Database-backed public event discovery
event.php             Database-backed public event, category and nominee details
contestant.php        Database-backed public nominee profiles
organizer/events.php Organizer-owned event management
organizer/analytics.php Authorized event insights and live standings
nominate.php         Public nomination submission
organizer/nominations.php Organizer nomination review
vote.php              Authenticated voter ballot submission
results.php           Visibility-controlled calculated results
*.html               Public page entry points
```

The shared JavaScript is retained for the static HTML preview. On a PHP-enabled web server, the public home, event listing, event details, nominee profiles, nominations, voting, results, authentication, and implemented organizer/admin workspaces use the PHP/database routes instead.

## Mock behavior and limitations

The public homepage at `index.php`, event discovery page at `events.php`, event details at `event.php`, and nominee profiles at `contestant.php` read published records from the database. Homepage statistics, event cards, categories, nominee profiles, and visible vote totals are calculated from stored records; vote counts remain hidden when the event's result visibility rules do not allow them. Draft, paused, and pending-review events remain private. Requests for the old `events.html`, `event-details.html`, or `contestant.html` previews redirect to database-backed pages when served through PHP. Approved organizers can create and manage event categories at `organizer/categories.php` and nominees at `organizer/contestants.php` until voting starts, including after event approval.

Approved organizers can manage nominees on published events until voting starts. Authorized organizers and event managers can access private event insights showing live standings and vote-share percentages, voter counts, a 14-day voting-activity chart, nomination counts, and successful local-simulator payment gross. Reports refresh every minute and are independent of public result visibility. Simulated totals are not real funds or withdrawable balances.

Platform administrators do not automatically receive organizer ownership. To enable organizer access for one active super-admin account, run `php scripts\grant_organizer_access.php email@example.com` from the project folder. This creates or approves only that account's organizer profile, assigns its organizer role, and records the grant in the audit log. The script refuses accounts that are not active super-admins.

Static `.html` pages opened directly from disk are a clearly separated frontend preview and may contain illustrative sample data; they do not represent database records. Use the PHP pages through a PHP-enabled web server to see real records. Protected admin pages show database-backed overview metrics, recent audit activity, pending organizer/event reviews, and read-only directories for all organizers, events, and users. General account-status changes, payment operations, reports, support, and other administrative actions are not implemented. Registration, login and logout are served by `auth.php`; accounts are stored in the database, and new organizer profiles remain pending approval. Approved organizers can create and edit event details before voting starts, submit events for platform review, and pause or close their own published events at `organizer/events.php`. Editing a published event before voting begins returns it to pending review. They can add, edit and deactivate categories at `organizer/categories.php`, and add, edit, assign or deactivate contestants at `organizer/contestants.php` while the event is editable. The organizer event list shows current category and nominee counts and links to setup pages. An event must have an active category with an assigned active nominee before an administrator can publish it. People can submit nominations to published events during the configured UTC nomination window at `nominate.php`; approved organizers can request information, reject, or approve applications at `organizer/nominations.php`. Approval creates a contestant and category assignment atomically. Signed-in voter accounts are automatically eligible to vote in open, published events with at least one active category/nominee assignment at `vote.php`; server-side event/category/contestant checks, voting windows, per-account limits, restricted ballots and request idempotency are enforced. In local development only, an explicitly enabled payment simulator can exercise paid checkout and verification without collecting money; only a successful simulated payment credits votes, once. Public totals at `results.php` are calculated from credited votes and obey each event's visibility configuration. Real paid voting remains disabled until a provider is integrated and its payment status is verified server-side. Records are retained rather than deleted. Supporting document uploads and applicant notifications are not implemented. File inputs on the mock pages are for preview only.

The SQL seed uses reserved `.example.test` addresses and leaves passwords unset; seeded accounts cannot be used to log in. Static preview figures are illustrative and are not linked to database records; protected organizer reports and public PHP results are calculated from database records. Password reset and email verification are not implemented yet.

This is not a production voting or election system. Password reset/email verification, account-status administration, rate limiting, and real payment processing are not implemented. The payment simulator is not a payment provider: it is guarded by `APP_ENV`, the `PAYMENT_MODE=simulator` opt-in, and a loopback-only request check. Keep it disabled outside local development. Static workspace dashboards are public mockups for local file preview only; protected server-side platform administration is available at `admin/index.php`, `admin/organizers.php`, `admin/events.php`, and `admin/users.php`.

## Next phase

The user approved changing the original sequence to **Frontend → Database → PHP backend → payments → security/testing/deployment**. Frontend and database phases are approved. Phase 2 includes backend workflows and an initial protected platform-admin area. The current payment milestone is a local-only simulator; a real provider integration remains future work and requires provider documentation, account approval, and test credentials.

### Local payment simulator

The simulator is disabled by default. After creating `.env`, change `PAYMENT_MODE=disabled` to `PAYMENT_MODE=simulator`, keep `APP_ENV=development`, and restart the local PHP server. The payment route is available only to requests whose remote address is `127.0.0.1` or `::1`. It does not contact Hubtel, Paystack, Flutterwave, or any other provider and it never collects money. On `vote.php`, a paid event displays a local checkout where you can simulate success or failure. Failed payments credit no votes; a successful payment records the payment and a single vote atomically. The server checks the event, voter, contestant/category assignment, price, voting window, account limit, CSRF token, and request idempotency before changing records.

To turn the simulator off, set `PAYMENT_MODE=disabled` again and restart the local server. Never set `PAYMENT_MODE=simulator` in a production environment.

### Platform administration

Create an account through the normal registration page, then promote that active account to super-admin from a trusted local terminal. Do not grant the role through public registration or expose database credentials:

```powershell
php scripts\grant_super_admin.php admin@example.com
```

Sign in using that account at `auth.php?action=login`; successful super-admin login redirects directly to `admin/index.php`. Admin access is not linked from public site navigation. The admin workspace shows database-backed counts and recent audit activity, supports organizer approval/rejection and event approval/rejection, and provides read-only organizer, event, and user directories. Decisions are written to `audit_logs`. Events need at least one active category and assigned nominee before approval. Paid events can only be approved on loopback while the local simulator is enabled; real payment processing remains unavailable. User/account administration beyond directory visibility remains future work.

Protected admin and approved-organizer pages use role-specific sidebars and an account menu with signed-in account details and sign-out. Public pages served over PHP update their header to show the signed-in account instead of login and registration actions. The account details screen displays identity and role information; profile editing and account preferences are not implemented.

### Authentication smoke check

After copying `.env.example` to `.env` and creating the development database, run:

```powershell
php tests\auth_smoke.php
```

The smoke check registers and removes a uniquely named temporary organizer account, verifies password authentication and duplicate-email rejection, and confirms organizer approval defaults to pending.

Run event-management checks with:

```powershell
php tests\event_smoke.php
```

This creates and removes temporary organizer accounts, events, categories, contestants, nominations, eligibility rows, ballots and simulated payment records. It checks ownership isolation, paid-vote configuration, nomination and voting windows, safe deactivation, atomic nomination approval, free-vote idempotency/limits, simulated payment success/failure, one-time vote crediting, restricted-single ballots, result visibility and allowed event lifecycle transitions. It uses no real payment provider or money.

Run platform-admin checks with:

```powershell
php tests\admin_smoke.php
```

This verifies super-admin access control, organizer and event moderation, audit logging, and the block on paid-event publication.
