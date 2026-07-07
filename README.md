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