# CPC Student Penalty Payment System

A PHP 8.2+ and MySQL application for role-based student penalty management and payment verification. This application lives in its own `cpc-system` folder and does not change the existing CPC app or its database.

## Project structure

```text
cpc-system/
|-- app/
|   |-- actions.php             # CSRF-protected POST actions and transactions
|   |-- auth.php                # Login state, role permissions, QR state
|   |-- bootstrap.php           # Secure session and common dependencies
|   |-- config.php              # Environment configuration
|   |-- database.php            # PDO connection
|   |-- helpers.php             # Escaping, CSRF, audit, pagination
|   |-- pages/                  # Dashboard, students, penalties, payments, channels, users, reports
|   `-- views/                  # Shared layout and pagination
|-- assets/
|   |-- css/app.css
|   `-- js/app.js
|-- tests/run.php               # Unit and rollback-based MySQL integration tests
|-- database.sql                # Schema, roles, and payment-channel seed data
|-- login.php
|-- logout.php
|-- index.php                   # Role-checked application router
|-- qr.php                      # Safe public payment-reference page
|-- qr-image.php                # Authenticated QR image endpoint
|-- setup-admin.php             # CLI-only initial administrator creation
|-- .env.example
`-- composer.json
```

## Requirements

- XAMPP with Apache, PHP 8.2 or newer, MySQL/MariaDB, PDO MySQL, and OpenSSL enabled.
- Composer.
- `endroid/qr-code` (installed through Composer for PNG QR generation).

## Install on XAMPP

1. Keep the `cpc-system` folder under `C:\xampp\htdocs\cpc-system`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin/`, choose **Import**, select `C:\xampp\htdocs\cpc-system\database.sql`, and import it. The script creates the `cpc_student_penalties` database, all application tables, the three roles, and the GCash, Maya, and CPC Cashier Office channels.
4. Ensure the PHP Zip extension is enabled for Composer. In XAMPP's `C:\xampp\php\php.ini`, enable `extension=zip` (the application itself does not require Zip). Then install the QR dependency:

   ```powershell
   cd C:\xampp\htdocs\cpc-system
   C:\xampp\php\php.exe -d extension=zip C:\composer\composer.phar install --no-interaction --prefer-dist
   ```

   If Composer is already configured to use a PHP installation with Zip enabled, `composer install` is sufficient. The QR endpoint emits SVG, so PHP GD is not required.

5. Copy `.env.example` to `.env`. The example uses XAMPP's common local MySQL defaults. Edit `CPC_DB_USER`, `CPC_DB_PASSWORD`, and other settings as needed. `.env` is blocked from direct web access and ignored by Git.
6. Create the first administrator from the command line. The password is prompted, not placed in source code:

   ```powershell
   php setup-admin.php admin@example.edu "CPC Administrator"
   ```

   Use a password of at least 12 characters. The `Admin` role must have been seeded by the database import.
7. Open `http://localhost/cpc-system/login.php` and sign in with the administrator account.

If PHP is not on `PATH`, use `C:\xampp\php\php.exe` and the Composer executable installed on your machine.

## Configuration

`.env.example` documents the supported variables:

| Variable | Default | Description |
|---|---|---|
| `CPC_DB_HOST` | `127.0.0.1` | MySQL host |
| `CPC_DB_PORT` | `3306` | MySQL port |
| `CPC_DB_NAME` | `cpc_student_penalties` | Database name |
| `CPC_DB_USER` | `root` | Local development database user |
| `CPC_DB_PASSWORD` | empty | Local development database password |
| `CPC_BASE_URL` | `/cpc-system` | App path below the web root |
| `CPC_APP_URL` | `http://localhost/cpc-system` | Public app URL embedded in QR references; configure the HTTPS URL for deployment |
| `CPC_QR_TTL_MINUTES` | `30` | QR reference lifetime (5–1440 minutes) |

Use a dedicated MySQL account and non-empty password outside local development. Do not commit `.env`, vendor credentials, or production secrets.

## Roles and workflows

- **Admin:** dashboard and reports; student search; create, edit, and deactivate accounts; create/edit penalties; manage channels; review payments.
- **Officer:** dashboard and reports; view student records; create/edit penalties; manage channels; verify or reject pending payments.
- **Student:** see personal dashboard and payment history; initiate a payment request for their own unpaid penalty using an active channel; view a time-limited QR payment reference.

Payment initiation records a pending request and generated reference. It does **not** move money and does **not** mark the penalty paid. Officers must verify the received transaction; that operation atomically marks the payment verified and the penalty paid. Rejected/expired requests can be re-submitted without creating a second payment row for the same penalty. Verification of one's own payment is forbidden.

The QR contains an unpredictable tokenized URL, never personal data. The reference expires, and becomes unusable after review or when replaced. There is no payment provider integration: scanning the code is not a payment.

## Tests

After importing the database and configuring `.env`, run:

```powershell
php tests/run.php
```

The integration checks create temporary users and records inside a database transaction and roll it back. They check role access, password hashing, CSRF token shape, QR expiration/usage, duplicate-payment constraints, and atomic payment/penalty state updates.

Also manually verify:

1. An unauthenticated request to `index.php?page=users` is redirected to login.
2. Student accounts cannot open admin/officer pages or submit penalty/channel/user actions.
3. A student can only create a payment request for their own unpaid penalty, with an active channel; repeating a live request is rejected.
4. An officer can reject a pending payment without marking the penalty paid, then verify a new request; only the verified request marks it paid.
5. An officer cannot verify their own payment.
6. A QR link is accepted before expiry, rejected after expiry, and unavailable after review or replacement.
7. Reports can be filtered and exported; the collected total includes verified payments only.

## Data and security notes

The database has the seven requested core tables plus `audit_logs` for the required audit trail. Database credentials are externalized. State-changing forms use session-bound CSRF tokens, outputs are escaped, passwords use PHP's password hashing API, SQL uses PDO prepared statements, and permissions are enforced server-side.
