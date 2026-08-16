<?php

return [
    /*
     * Where each driver's dump binary lives, and how to find it.
     *
     * `path` pins the directory outright (skip discovery). `globs` are
     * version-managed install roots, each holding a <root>/bin/<binary>.
     * `search_paths` are extra directories tried when the binary is not on PATH.
     *
     * PostgreSQL considers every glob match and picks the NEWEST pg_dump, because
     * pg_dump refuses to dump a server newer than itself — so a Postgres 17 server
     * is dumped by a 17 client even when an older libpq sits earlier on PATH.
     * mysqldump has no equivalent rule, so the first match is used and no version
     * probe is run.
     */
    'binaries' => [
        'pgsql' => [
            'path' => env('PG_DUMP_PATH'),

            'globs' => [
                '/Users/Shared/DBngin/postgresql/*',
                '/opt/homebrew/opt/postgresql@*',
                '/opt/homebrew/Cellar/postgresql@*/*',
                '/usr/local/opt/postgresql@*',
                '/Applications/Postgres.app/Contents/Versions/*',
            ],

            'search_paths' => [
                '/opt/homebrew/opt/libpq/bin',
                '/usr/local/opt/libpq/bin',
                '/opt/homebrew/bin',
                '/usr/local/bin',
            ],
        ],

        'mysql' => [
            'path' => env('MYSQLDUMP_PATH'),

            'globs' => [
                '/Users/Shared/DBngin/mysql/*',
                '/opt/homebrew/opt/mysql@*',
                '/opt/homebrew/opt/mysql-client@*',
                '/usr/local/opt/mysql@*',
                '/usr/local/opt/mysql-client@*',
            ],

            'search_paths' => [
                '/opt/homebrew/opt/mysql-client/bin',
                '/usr/local/opt/mysql-client/bin',
                '/opt/homebrew/opt/mariadb/bin',
                '/opt/homebrew/bin',
                '/usr/local/bin',
                '/usr/bin',
            ],
        ],
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
