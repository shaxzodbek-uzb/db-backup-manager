# DB Backup Manager

A Laravel 12 + Vue tool for secure, scheduled database backups. Define source database
**connections**, pick **destinations** (S3 / DigitalOcean Spaces, Telegram, …), schedule
backup **plans**, and track every **run** and stored **artifact**.

PostgreSQL is the first-class source; MySQL support is planned.

## Features

- **Connections** — register source databases, test connectivity, list databases
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

> Requires the relevant DB client binaries (e.g. `pg_dump`) on the host for dumps to run.
