<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_guests_cannot_access_connections(): void
    {
        $this->get(route('connections.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_connections_without_secrets(): void
    {
        Connection::factory()->create(['name' => 'Prod DB']);

        $this->actingAs($this->admin())
            ->get(route('connections.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('connections/Index')
                ->has('connections', 1)
                ->where('connections.0.name', 'Prod DB')
                ->where('connections.0.has_password', true)
                ->missing('connections.0.password'));
    }

    public function test_admin_can_create_a_connection(): void
    {
        $this->actingAs($this->admin())
            ->post(route('connections.store'), [
                'name' => 'New DB',
                'driver' => 'mysql',
                'host' => 'db.example.com',
                'port' => 3306,
                'username' => 'root',
                'password' => 'super-secret',
            ])
            ->assertRedirect(route('connections.index'));

        $this->assertDatabaseHas('connections', ['name' => 'New DB', 'host' => 'db.example.com']);
        $this->assertSame('super-secret', Connection::firstWhere('name', 'New DB')->password);
    }

    public function test_store_validates_input(): void
    {
        $this->actingAs($this->admin())
            ->post(route('connections.store'), [
                'name' => '',
                'driver' => 'oracle',
                'port' => 70000,
            ])
            ->assertSessionHasErrors(['name', 'driver', 'host', 'port', 'username']);
    }

    public function test_password_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $connection = Connection::factory()->create(['password' => 'plain-secret']);

        $raw = DB::table('connections')->where('id', $connection->id)->value('password');
        $this->assertNotSame('plain-secret', $raw);
        $this->assertSame('plain-secret', Crypt::decryptString($raw));

        $array = $connection->fresh()->toArray();
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayHasKey('host', $array);
    }

    public function test_edit_does_not_expose_secret(): void
    {
        $connection = Connection::factory()->create(['password' => 'top-secret']);

        $this->actingAs($this->admin())
            ->get(route('connections.edit', $connection))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('connections/Form')
                ->where('connection.has_password', true)
                ->missing('connection.password'));
    }

    public function test_blank_password_keeps_the_existing_one_on_update(): void
    {
        $connection = Connection::factory()->create(['password' => 'keep-me', 'name' => 'Old']);

        $this->actingAs($this->admin())
            ->put(route('connections.update', $connection), [
                'name' => 'Renamed',
                'driver' => $connection->driver,
                'host' => $connection->host,
                'port' => $connection->port,
                'username' => $connection->username,
                'password' => '',
            ])
            ->assertRedirect(route('connections.index'));

        $connection->refresh();
        $this->assertSame('Renamed', $connection->name);
        $this->assertSame('keep-me', $connection->password);
    }

    public function test_a_new_password_replaces_the_existing_one_on_update(): void
    {
        $connection = Connection::factory()->create(['password' => 'old-pw']);

        $this->actingAs($this->admin())
            ->put(route('connections.update', $connection), [
                'name' => $connection->name,
                'driver' => $connection->driver,
                'host' => $connection->host,
                'port' => $connection->port,
                'username' => $connection->username,
                'password' => 'new-pw',
            ]);

        $this->assertSame('new-pw', $connection->refresh()->password);
    }

    public function test_admin_can_delete_a_connection(): void
    {
        $connection = Connection::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('connections.destroy', $connection))
            ->assertRedirect(route('connections.index'));

        $this->assertDatabaseMissing('connections', ['id' => $connection->id]);
    }

    public function test_test_endpoint_records_failure_for_an_unreachable_server(): void
    {
        $connection = Connection::factory()->create([
            'host' => '127.0.0.1',
            'port' => 1, // nothing listening → fast refusal
        ]);

        $this->actingAs($this->admin())
            ->post(route('connections.test', $connection))
            ->assertRedirect();

        $connection->refresh();
        $this->assertNotNull($connection->last_tested_at);
        $this->assertFalse($connection->last_test_ok);
        $this->assertNotNull($connection->last_test_error);
    }

    public function test_test_endpoint_is_rate_limited(): void
    {
        $connection = Connection::factory()->create(['host' => '127.0.0.1', 'port' => 1]);
        $admin = $this->admin();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)
                ->post(route('connections.test', $connection))
                ->assertRedirect();
        }

        $this->actingAs($admin)
            ->post(route('connections.test', $connection))
            ->assertStatus(429);
    }

    public function test_databases_route_handles_an_unreachable_server_gracefully(): void
    {
        $connection = Connection::factory()->create(['host' => '127.0.0.1', 'port' => 1]);

        $this->actingAs($this->admin())
            ->get(route('connections.databases', $connection))
            ->assertRedirect();
    }
}
