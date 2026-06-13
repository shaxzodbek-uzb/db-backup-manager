<?php

namespace App\Http\Controllers;

use App\Concerns\RedactsSecrets;
use App\Http\Requests\StoreConnectionRequest;
use App\Http\Requests\UpdateConnectionRequest;
use App\Models\Connection;
use App\Services\Db\ConnectionTester;
use App\Services\Db\DatabaseLister;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ConnectionController extends Controller
{
    use RedactsSecrets;

    public function __construct(
        private ConnectionTester $tester,
        private DatabaseLister $lister,
    ) {}

    public function index(): Response
    {
        return Inertia::render('connections/Index', [
            'connections' => Connection::query()
                ->latest()
                ->get()
                ->map(fn (Connection $connection): array => $this->present($connection)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('connections/Form', ['connection' => null]);
    }

    public function store(StoreConnectionRequest $request): RedirectResponse
    {
        Connection::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection created.')]);

        return to_route('connections.index');
    }

    public function edit(Connection $connection): Response
    {
        return Inertia::render('connections/Form', ['connection' => $this->present($connection)]);
    }

    public function update(UpdateConnectionRequest $request, Connection $connection): RedirectResponse
    {
        $data = $request->validated();

        // Write-only secrets: a blank submission keeps the stored value.
        foreach (Connection::SECRETS as $secret) {
            if (! filled($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $connection->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection updated.')]);

        return to_route('connections.index');
    }

    public function destroy(Connection $connection): RedirectResponse
    {
        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection deleted.')]);

        return to_route('connections.index');
    }

    public function test(Connection $connection): RedirectResponse
    {
        $result = $this->tester->test($connection);

        $connection->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_error' => $result->error,
        ])->save();

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('Connection successful (:ms ms).', ['ms' => $result->latencyMs])]
            : ['type' => 'error', 'message' => __('Connection failed: :error', ['error' => $result->error])]);

        return back();
    }

    public function databases(Connection $connection): Response|RedirectResponse
    {
        try {
            $databases = $this->lister->list($connection);
        } catch (Throwable $e) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Could not list databases: :error', [
                    'error' => $this->redactSecrets($e->getMessage(), $connection),
                ]),
            ]);

            return back();
        }

        return Inertia::render('connections/Databases', [
            'connection' => $this->present($connection),
            'databases' => $databases,
        ]);
    }

    /**
     * Build the frontend-safe representation — never includes secret values,
     * only "is it set" flags.
     *
     * @return array<string, mixed>
     */
    private function present(Connection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'driver' => $connection->driver,
            'host' => $connection->host,
            'port' => $connection->port,
            'username' => $connection->username,
            'has_password' => filled($connection->password),
            // TLS — secret values never leave the server, only "is it set" flags.
            'ssl_mode' => $connection->ssl_mode,
            'has_ssl_ca' => filled($connection->ssl_ca),
            'has_ssl_cert' => filled($connection->ssl_cert),
            'has_ssl_key' => filled($connection->ssl_key),
            // SSH tunnel.
            'ssh_enabled' => $connection->ssh_enabled,
            'ssh_host' => $connection->ssh_host,
            'ssh_port' => $connection->ssh_port,
            'ssh_user' => $connection->ssh_user,
            'ssh_auth' => $connection->ssh_auth,
            'has_ssh_private_key' => filled($connection->ssh_private_key),
            'has_ssh_passphrase' => filled($connection->ssh_passphrase),
            'last_tested_at' => $connection->last_tested_at?->toIso8601String(),
            'last_test_ok' => $connection->last_test_ok,
            'last_test_error' => $connection->last_test_error,
        ];
    }
}
