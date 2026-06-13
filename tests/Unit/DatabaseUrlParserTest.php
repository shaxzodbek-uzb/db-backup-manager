<?php

namespace Tests\Unit;

use App\Support\DatabaseUrlParser;
use Tests\TestCase;

class DatabaseUrlParserTest extends TestCase
{
    public function test_it_parses_a_full_postgres_url(): void
    {
        $parsed = DatabaseUrlParser::parse('postgresql://bob:s3cret@db.example.com:5433/shop');

        $this->assertSame([
            'driver' => 'pgsql',
            'host' => 'db.example.com',
            'port' => 5433,
            'username' => 'bob',
            'password' => 's3cret',
        ], $parsed);
    }

    public function test_it_defaults_the_port_per_driver(): void
    {
        $this->assertSame(5432, DatabaseUrlParser::parse('postgres://u@h/db')['port']);
        $this->assertSame(3306, DatabaseUrlParser::parse('mysql://u@h/db')['port']);
    }

    public function test_it_maps_scheme_aliases_to_a_driver(): void
    {
        $this->assertSame('pgsql', DatabaseUrlParser::parse('pgsql://u@h/db')['driver']);
        $this->assertSame('mysql', DatabaseUrlParser::parse('mariadb://u@h/db')['driver']);
    }

    public function test_it_url_decodes_credentials(): void
    {
        $parsed = DatabaseUrlParser::parse('postgres://user%40corp:p%40ss%20word@h:5432/db');

        $this->assertSame('user@corp', $parsed['username']);
        $this->assertSame('p@ss word', $parsed['password']);
    }

    public function test_it_returns_null_for_unsupported_or_invalid_urls(): void
    {
        $this->assertNull(DatabaseUrlParser::parse('redis://h:6379'));
        $this->assertNull(DatabaseUrlParser::parse('not a url'));
        $this->assertNull(DatabaseUrlParser::parse(''));
    }
}
