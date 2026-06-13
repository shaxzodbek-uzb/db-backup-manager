<?php

return [
    /*
     * Directory containing the dump binaries (pg_dump). Leave null to auto-detect
     * from PATH and the common Homebrew/libpq locations listed below.
     */
    'pg_dump_path' => env('PG_DUMP_PATH'),

    /*
     * Version-managed install roots (each holds a <root>/bin/pg_dump). Every
     * match is considered and the newest pg_dump wins — so a Postgres 17 server
     * is dumped by a 17 client even when an older libpq sits on PATH.
     */
    'binary_globs' => [
        '/Users/Shared/DBngin/postgresql/*',
        '/opt/homebrew/opt/postgresql@*',
        '/opt/homebrew/Cellar/postgresql@*/*',
        '/usr/local/opt/postgresql@*',
        '/Applications/Postgres.app/Contents/Versions/*',
    ],

    /*
     * Extra directories searched for the dump binary when it is not on PATH.
     */
    'binary_search_paths' => [
        '/opt/homebrew/opt/libpq/bin',
        '/usr/local/opt/libpq/bin',
        '/opt/homebrew/bin',
        '/usr/local/bin',
    ],

    /*
     * Local staging directory (relative to storage/app) where dumps are written
     * before being shipped to a destination.
     */
    'staging_path' => 'backups',

    /*
     * Per-database dump timeout in seconds.
     */
    'timeout' => (int) env('BACKUP_TIMEOUT', 3600),
];
