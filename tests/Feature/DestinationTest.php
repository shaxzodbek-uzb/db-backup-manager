<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DestinationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function s3Payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Off-site Spaces',
            'type' => 's3',
            'config' => [
                'region' => 'fra1',
                'bucket' => 'backups',
                'access_key' => 'DO00EXAMPLE',
                'secret_key' => 'super-secret-key',
                'endpoint' => 'https://fra1.digitaloceanspaces.com',
                'prefix' => 'db',
                'use_path_style' => false,
            ],
        ], $overrides);
    }

    public function test_guests_cannot_access_destinations(): void
    {
        $this->get(route('destinations.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_destinations_without_secrets(): void
    {
        Destination::factory()->create(['name' => 'Spaces']);

        $this->actingAs($this->admin())
            ->get(route('destinations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('destinations/Index')
                ->has('destinations', 1)
                ->where('destinations.0.name', 'Spaces')
                ->where('destinations.0.config.bucket', 'backups')
                ->where('destinations.0.secrets.secret_key', true)
                ->missing('destinations.0.config.secret_key'));
    }

    public function test_admin_can_create_an_s3_destination(): void
    {
        $this->actingAs($this->admin())
            ->post(route('destinations.store'), $this->s3Payload())
            ->assertRedirect(route('destinations.index'));

        $destination = Destination::firstWhere('name', 'Off-site Spaces');
        $this->assertSame('s3', $destination->type);
        $this->assertSame('backups', $destination->config['bucket']);
        $this->assertSame('super-secret-key', $destination->config['secret_key']);
    }

    public function test_admin_can_create_a_telegram_destination(): void
    {
        $this->actingAs($this->admin())
            ->post(route('destinations.store'), [
                'name' => 'Backup channel',
                'type' => 'telegram',
                'config' => [
                    'chat_id' => '-1001234567890',
                    'bot_token' => '123456:abc-token',
                ],
            ])
            ->assertRedirect(route('destinations.index'));

        $destination = Destination::firstWhere('name', 'Backup channel');
        $this->assertSame('telegram', $destination->type);
        $this->assertSame('-1001234567890', $destination->config['chat_id']);
        $this->assertSame('123456:abc-token', $destination->config['bot_token']);
    }

    public function test_store_validates_s3_fields(): void
    {
        $this->actingAs($this->admin())
            ->post(route('destinations.store'), ['name' => '', 'type' => 's3', 'config' => []])
            ->assertSessionHasErrors([
                'name',
                'config.region',
                'config.bucket',
                'config.access_key',
                'config.secret_key',
            ]);
    }

    public function test_store_validates_telegram_fields(): void
    {
        $this->actingAs($this->admin())
            ->post(route('destinations.store'), ['name' => 'TG', 'type' => 'telegram', 'config' => []])
            ->assertSessionHasErrors(['config.chat_id', 'config.bot_token']);
    }

    public function test_store_rejects_unknown_type(): void
    {
        $this->actingAs($this->admin())
            ->post(route('destinations.store'), ['name' => 'X', 'type' => 'ftp', 'config' => []])
            ->assertSessionHasErrors(['type']);
    }

    public function test_config_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $destination = Destination::factory()->create();

        $raw = DB::table('destinations')->where('id', $destination->id)->value('config');
        $this->assertStringNotContainsString('super-secret-key', $raw);
        $this->assertSame('super-secret-key', json_decode(Crypt::decryptString($raw), true)['secret_key']);

        $this->assertArrayNotHasKey('config', $destination->fresh()->toArray());
    }

    public function test_edit_does_not_expose_secret(): void
    {
        $destination = Destination::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('destinations.edit', $destination))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('destinations/Form')
                ->where('destination.secrets.secret_key', true)
                ->missing('destination.config.secret_key'));
    }

    public function test_blank_secret_keeps_the_existing_one_on_update(): void
    {
        $destination = Destination::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('destinations.update', $destination), $this->s3Payload([
                'name' => 'Renamed',
                'config' => array_merge($this->s3Payload()['config'], ['secret_key' => '']),
            ]))
            ->assertRedirect(route('destinations.index'));

        $destination->refresh();
        $this->assertSame('Renamed', $destination->name);
        $this->assertSame('super-secret-key', $destination->config['secret_key']);
    }

    public function test_a_new_secret_replaces_the_existing_one_on_update(): void
    {
        $destination = Destination::factory()->create();

        $this->actingAs($this->admin())
            ->put(route('destinations.update', $destination), $this->s3Payload([
                'config' => array_merge($this->s3Payload()['config'], ['secret_key' => 'rotated-key']),
            ]));

        $this->assertSame('rotated-key', $destination->refresh()->config['secret_key']);
    }

    public function test_admin_can_delete_a_destination(): void
    {
        $destination = Destination::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('destinations.destroy', $destination))
            ->assertRedirect(route('destinations.index'));

        $this->assertDatabaseMissing('destinations', ['id' => $destination->id]);
    }

    public function test_s3_test_endpoint_records_failure_for_an_unreachable_endpoint(): void
    {
        $destination = Destination::factory()->create([
            'config' => [
                'region' => 'us-east-1',
                'bucket' => 'backups',
                'access_key' => 'key',
                'secret_key' => 'secret',
                'endpoint' => 'http://127.0.0.1:1', // nothing listening
                'prefix' => '',
                'use_path_style' => true,
            ],
        ]);

        $this->actingAs($this->admin())
            ->post(route('destinations.test', $destination))
            ->assertRedirect();

        $destination->refresh();
        $this->assertNotNull($destination->last_tested_at);
        $this->assertFalse($destination->last_test_ok);
        $this->assertNotNull($destination->last_test_error);
    }

    public function test_telegram_test_endpoint_succeeds_when_the_api_accepts(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200)]);

        $destination = Destination::factory()->telegram()->create();

        $this->actingAs($this->admin())
            ->post(route('destinations.test', $destination))
            ->assertRedirect();

        $this->assertTrue($destination->refresh()->last_test_ok);
    }

    public function test_telegram_test_endpoint_fails_when_the_token_is_rejected(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $destination = Destination::factory()->telegram()->create();

        $this->actingAs($this->admin())
            ->post(route('destinations.test', $destination))
            ->assertRedirect();

        $destination->refresh();
        $this->assertFalse($destination->last_test_ok);
        $this->assertNotNull($destination->last_test_error);
    }

    public function test_test_endpoint_is_rate_limited(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $destination = Destination::factory()->telegram()->create();
        $admin = $this->admin();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)
                ->post(route('destinations.test', $destination))
                ->assertRedirect();
        }

        $this->actingAs($admin)
            ->post(route('destinations.test', $destination))
            ->assertStatus(429);
    }
}
