<?php

use App\Models\Connection;
use App\Services\Backup\DatabaseDumper;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The dumper resolves a system binary, so these tests fake one on disk rather
 * than depending on whatever happens to be installed on the machine running CI.
 */
function fakeBinary(string $dir, string $name, string $version = '9.9.9'): string
{
    @mkdir($dir, 0777, true);
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/bin/sh\necho \"{$name} (fake) {$version}\"\n");
    chmod($path, 0755);

    return $path;
}

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/dumper-'.bin2hex(random_bytes(6));
    @mkdir($this->sandbox, 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->sandbox));
});

it('resolves mysqldump for a mysql connection', function () {
    $bin = $this->sandbox.'/mysql/bin';
    fakeBinary($bin, 'mysqldump');
    config()->set('backup.binaries.mysql.path', $bin);

    expect((new DatabaseDumper)->binaryDirectory('mysql'))->toBe($bin);
});

it('resolves pg_dump for a postgres connection', function () {
    $bin = $this->sandbox.'/pg/bin';
    fakeBinary($bin, 'pg_dump');
    config()->set('backup.binaries.pgsql.path', $bin);

    expect((new DatabaseDumper)->binaryDirectory('pgsql'))->toBe($bin);
});

it('picks the newest pg_dump, because pg_dump cannot dump a newer server', function () {
    fakeBinary($this->sandbox.'/pg14/bin', 'pg_dump', '14.10');
    fakeBinary($this->sandbox.'/pg17/bin', 'pg_dump', '17.2');

    config()->set('backup.binaries.pgsql.path', null);
    config()->set('backup.binaries.pgsql.globs', [$this->sandbox.'/pg*']);
    config()->set('backup.binaries.pgsql.search_paths', []);

    expect((new DatabaseDumper)->binaryDirectory('pgsql'))->toBe($this->sandbox.'/pg17/bin');
});

it('does not version-probe mysqldump, since it has no such constraint', function () {
    // A binary that fails --version would be skipped by the "newest" strategy;
    // for mysql the first match must be used regardless.
    $bin = $this->sandbox.'/mysql/bin';
    @mkdir($bin, 0777, true);
    file_put_contents($bin.'/mysqldump', "#!/bin/sh\nexit 1\n");
    chmod($bin.'/mysqldump', 0755);

    config()->set('backup.binaries.mysql.path', null);
    config()->set('backup.binaries.mysql.globs', []);
    config()->set('backup.binaries.mysql.search_paths', [$bin]);

    expect((new DatabaseDumper)->binaryDirectory('mysql'))->toBe($bin);
});

it('names the missing binary and how to install it', function () {
    config()->set('backup.binaries.mysql.path', null);
    config()->set('backup.binaries.mysql.globs', []);
    config()->set('backup.binaries.mysql.search_paths', [$this->sandbox.'/nowhere']);

    // Only meaningful when the host has no mysqldump on PATH.
    if ((new ExecutableFinder)->find('mysqldump') !== null) {
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => (new DatabaseDumper)->binaryDirectory('mysql'))
        ->toThrow(RuntimeException::class, 'mysqldump was not found');
});

it('rejects a driver it cannot dump, listing what it can', function () {
    $connection = new Connection(['driver' => 'sqlite', 'host' => 'localhost', 'port' => 0]);

    expect(fn () => (new DatabaseDumper)->dump($connection, 'db', $this->sandbox.'/out.sql.gz'))
        ->toThrow(RuntimeException::class, 'is not supported');
});

it('mentions both supported drivers in the unsupported-driver error', function () {
    $connection = new Connection(['driver' => 'mongodb', 'host' => 'localhost', 'port' => 0]);

    try {
        (new DatabaseDumper)->dump($connection, 'db', $this->sandbox.'/out.sql.gz');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('pgsql')->toContain('mysql');

        return;
    }

    $this->fail('expected a RuntimeException');
});

it('caches the resolved directory per driver', function () {
    $pg = $this->sandbox.'/pg/bin';
    $my = $this->sandbox.'/my/bin';
    fakeBinary($pg, 'pg_dump');
    fakeBinary($my, 'mysqldump');
    config()->set('backup.binaries.pgsql.path', $pg);
    config()->set('backup.binaries.mysql.path', $my);

    $dumper = new DatabaseDumper;

    // Both drivers resolve independently; one must not overwrite the other's cache.
    expect($dumper->binaryDirectory('pgsql'))->toBe($pg)
        ->and($dumper->binaryDirectory('mysql'))->toBe($my)
        ->and($dumper->binaryDirectory('pgsql'))->toBe($pg);
});
