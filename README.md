# RedAgos Server

RedAgos Server is the Laravel API backend for the RedAgos blood request and inventory management system. It provides authentication, user data, and the database foundation for donor profiles, facilities, blood inventory, requests, billing, and payments.

## Tech Stack

- PHP 8.3+
- Laravel 13
- Laravel Sanctum for API token authentication
- MariaDB or MySQL
- Composer
- PHPUnit for tests

## Project Structure

```text
RedAgos_server/
|-- app/
|   |-- Http/Controllers/     # API controllers
|   `-- Models/               # Eloquent models
|-- database/
|   |-- factories/            # Test and seed model factories
|   |-- migrations/           # One migration per table
|   `-- seeders/              # Development seed data
|-- routes/
|   |-- api.php               # API routes
|   `-- web.php               # Web routes
|-- composer.json
`-- README.md
```

## Prerequisites

Make sure these are installed and available in your terminal:

```bash
php --version
composer --version
mysql --version
```

Recommended versions:

- PHP 8.3
- Composer 2.x
- MariaDB/MySQL running locally on port `3306`

## Environment Setup

From the server project directory:

```bash
cd ~/RedAgos_server
composer install
cp .env.example .env
php artisan key:generate
```

Update `.env` for your local database:

```env
APP_NAME=RedAgos
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=redagos_db
DB_USERNAME=root
DB_PASSWORD=
```

If your MariaDB user does not allow `root` login from localhost, create a dedicated user and use that in `.env`:

```sql
CREATE DATABASE IF NOT EXISTS redagos_db;
CREATE USER IF NOT EXISTS 'redagos'@'localhost' IDENTIFIED BY 'password';
GRANT ALL PRIVILEGES ON redagos_db.* TO 'redagos'@'localhost';
FLUSH PRIVILEGES;
```

Then update:

```env
DB_USERNAME=redagos
DB_PASSWORD=password
```

After changing `.env`, clear cached config:

```bash
php artisan config:clear
```

## Database Setup

Run migrations:

```bash
php artisan migrate
```

Seed the development test user:

```bash
php artisan db:seed
```

To reset all tables and seed again:

```bash
php artisan migrate:fresh --seed
```

Use `migrate:fresh --seed` only when you are okay deleting all existing local data.

## Test Login Account

The default `DatabaseSeeder` creates this local test user:

```text
Email: test@example.com
Password: password
```

The current `users` table stores names as `first_name` and `last_name`, plus `username` and `uuid`. Keep the factory, seeder, and model fillable fields aligned with that schema.

## Sample Blood Center Staff (local only)

`test@example.com` above has no role, so it cannot sign in to any portal. To try the blood-center portal as each post, seed one account per role at **Sub-National Blood Center**:

```bash
php artisan db:seed                                          # creates the facility (FacilitySeeder)
php artisan db:seed --class=BloodCenterStaffSeeder
```

Every account is verified and active, signs in to the **Blood Center** portal, and shares one password:

```text
Password: Password123
```

The seeder refuses to run outside the `local` and `testing` environments, even with `--force`: it creates verified accounts that share a known password. Re-running it creates nothing new and never resets a password. Run it **before** `DemoInventorySeeder`, which attributes its records to the facility's first staff account and skips a facility with none.

★ marks each department's **head**, who approves that department's correction requests. A head's own request is decided by the Center Admin.

| Department | Role | Name | Email | Lands on |
|---|---|---|---|---|
| Management | Center Admin | Teresa Aquino | `supervisor@redagos.test` | `/blood-center/dashboard` |
| Donor/Collection | ★ Donor Screening Physician | Ramon Villanueva | `screening.physician@redagos.test` | `/blood-center/collection` |
| Donor/Collection | Phlebotomist / Registered Nurse | Liza Bautista | `phlebotomist@redagos.test` | `/blood-center/collection` |
| Donor/Collection | Apheresis Specialist | Marvin Castillo | `apheresis.specialist@redagos.test` | `/blood-center/collection` |
| Donor/Collection | Donor Care / Medical Receptionist | Grace Domingo | `medical.receptionist@redagos.test` | `/blood-center/appointments` |
| Processing | ★ Component Laboratory Medical Technologist | Arnel Navarro | `component.technologist@redagos.test` | `/blood-center/laboratory` |
| Processing | Processing Laboratory Assistant | Joy Salazar | `processing.assistant@redagos.test` | `/blood-center/laboratory` |
| Testing | ★ Laboratory Supervisor | Dennis Ocampo | `lab.supervisor@redagos.test` | `/blood-center/testing` |
| Testing | Serology / Molecular Medical Technologist | Kristine Lim | `serology.technologist@redagos.test` | `/blood-center/testing` |
| Issuance | ★ Inventory Control Officer | Rowena Tan | `inventory.control.officer@redagos.test` | `/blood-center/storage` |
| Issuance | Dispatch / Transport Coordinator | Jerome Flores | `dispatch.coordinator@redagos.test` | `/blood-center/fulfillment` |
| Issuance | IT Data Entry Clerk | Paolo Rivera | `it.data.clerk@redagos.test` | `/blood-center/inventory` |
| Billing | ★ Billing Supervisor | Carmela Reyes | `billing.supervisor@redagos.test` | `/blood-center/billing` |
| Billing | Billing Clerk | Andrea Mendoza | `billing.clerk@redagos.test` | `/blood-center/billing` |

The "Lands on" pages come from the client's `useBloodCenterNav.ts`.

Try the correction flow with these accounts:

- `it.data.clerk@` files a correction to a unit's storage location or expiry date, and `inventory.control.officer@` approves it.
- `dispatch.coordinator@` files a correction to a dispatch record, and `inventory.control.officer@` approves it.
- `billing.clerk@` files a correction to a recorded payment, and `billing.supervisor@` approves it.

## Running the API

Start the Laravel development server:

```bash
php artisan serve
```

Default API base URL:

```text
http://127.0.0.1:8000/api
```

The Nuxt client should point to this value in its `.env`:

```env
API_BASE_URL=http://127.0.0.1:8000/api
```

## API Endpoints

### Login

```http
POST /api/login
```

Request body:

```json
{
  "email": "test@example.com",
  "password": "password"
}
```

Successful response includes the authenticated user and a Sanctum bearer token:

```json
{
  "user": {},
  "token": "plain-text-token",
  "token_type": "Bearer"
}
```

### Current User

```http
GET /api/user
Authorization: Bearer <token>
```

This route is protected by Sanctum.

## Development Commands

Install dependencies:

```bash
composer install
```

Run migrations:

```bash
php artisan migrate
```

Seed data:

```bash
php artisan db:seed
```

Start server:

```bash
php artisan serve
```

Run tests:

```bash
php artisan test
```

Format code with Laravel Pint:

```bash
./vendor/bin/pint
```

Clear common caches:

```bash
php artisan optimize:clear
```

## Scheduled Tasks

Three scheduled commands are registered in `routes/console.php`. Each runs on its own cadence, and
none of them can be left out:

| Command | Runs | What it does |
|---|---|---|
| `inventory:expire-units` | daily 00:30 `Asia/Manila` | Moves past-expiry centre units off the shelf. |
| `hospital:expire-tags` | every minute | Ends hospital blood bank tags whose 24-hour crossmatch or transfusion period has run out, and frees the bag. |
| `hospital:expire-units` | daily 00:30 `Asia/Manila` | Moves past-expiry bags on a hospital blood bank's own shelf to expired. |

**Installing the scheduler is a release requirement, not an optimisation.** If nothing invokes it,
past-expiry units keep reporting as `available` and the API is confidently wrong about issuable
stock.

Server — one cron entry, which is all Laravel ever needs:

```bash
* * * * * cd /path/to/RedAgos_server && php artisan schedule:run >> /dev/null 2>&1
```

On a managed platform (Laravel Cloud and similar), enable that platform's scheduler for the app
instead; it invokes `schedule:run` on the same minute cadence. Do not add a second cron.

Local development:

```bash
php artisan schedule:work          # run the scheduler in the foreground
php artisan inventory:expire-units # or run the sweep by hand
```

Verify after deploying:

```bash
php artisan schedule:list   # inventory:expire-units, 30 0 * * *, next due in Manila time
php artisan schedule:test   # run a scheduled task on demand
```

Verify it stayed running: the sweep writes an `inventory.expiry_swept` row to `audit_logs` on
**every** run, including ones that expire nothing. The absence of yesterday's row is proof the
scheduler is down, rather than proof it was a quiet day. The hospital expiry sweep does the same
with `hospital_inventory.expiry_swept`.

The per-minute tag sweep writes a `hospital_inventory.tag_sweep` run row only when it untags
something, because a row for every quiet minute would be noise. Its health signal is instead
`overdue_active_tags` in `GET /api/hospital/inventory/summary`: if that stays above zero for more
than a minute, the scheduler is not running.

```bash
php artisan hospital:expire-tags    # end lapsed hospital tags by hand
php artisan hospital:expire-units   # expire past-date hospital bags by hand
```

## Migration Guidelines

Domain migrations are intentionally split into one file per table to follow the Single Responsibility Principle. Keep each migration focused on one table and name it clearly, for example:

```text
2026_07_06_000013_create_blood_requests_table.php
```

When adding tables with foreign keys, order the migration timestamps so parent tables run before child tables.

## Authentication Notes

- The backend issues Sanctum personal access tokens from `/api/login`.
- The frontend stores the returned token in `localStorage` as `_token`.
- Protected frontend routes should send the token as `Authorization: Bearer <token>`.

## Troubleshooting

### Host is not allowed to connect

If MariaDB returns:

```text
SQLSTATE[HY000] [1130] Host 'localhost' is not allowed to connect
```

Use a valid database user for `localhost` or `127.0.0.1`, then clear Laravel config:

```bash
php artisan config:clear
```

### Unknown column `name` in users table

The project user schema does not use a `name` column. Use:

```text
first_name
last_name
username
uuid
email
password
```

If this happens while seeding, check `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`, and `app/Models/User.php`.

## Related Project

Frontend client repository:

```text
../RedAgos_client
```

Run the client separately with:

```bash
cd ~/RedAgos_client
npm run dev
```