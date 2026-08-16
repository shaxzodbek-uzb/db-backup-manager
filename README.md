# DB Backup Manager

A Laravel 12 + Vue tool for secure, scheduled database backups. Define source database
**connections**, pick **destinations** (S3 / DigitalOcean Spaces, Telegram, …), schedule
backup **plans**, and track every **run** and stored **artifact**.

PostgreSQL and MySQL/MariaDB are both supported as sources.

## Features

- **Connections** — register PostgreSQL or MySQL/MariaDB sources, test connectivity, list databases
- **Destinations** — configure and test backup targets (S3-compatible storage, Telegram)
- **Backup plans & runs** — scheduled backups with a full run history
- **Artifacts** — track each produced dump file
- **Auth** — Fortify with passkey support (`@laravel/passkeys`)

## Stack

- PHP, Laravel 12, Inertia.js + Vue 3
- `spatie/db-dumper`, `league/flysystem-aws-s3-v3`, Fortify, Wayfinder

## Local Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

> Dumps shell out to the DB client binary, so the host needs `pg_dump` (PostgreSQL)
> and/or `mysqldump` (MySQL/MariaDB). Both are auto-detected from PATH and the usual
> Homebrew/DBngin locations; pin them with `PG_DUMP_PATH` / `MYSQLDUMP_PATH` if needed.
>
> For PostgreSQL the **newest** `pg_dump` found wins, because `pg_dump` refuses to dump
> a server newer than itself. `mysqldump` has no such rule, so the first match is used.
