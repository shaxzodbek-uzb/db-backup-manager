<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Models\Destination;
use App\Services\Backup\BackupStats;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(BackupStats $stats): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'connections' => $this->breakdown(Connection::class),
                'destinations' => $this->breakdown(Destination::class),
            ],
            'backupDaily' => $stats->daily(),
            'recentConnections' => Connection::query()->latest()->take(5)->get()
                ->map(fn (Connection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'driver' => $connection->driver,
                    'host' => $connection->host,
                    'last_test_ok' => $connection->last_test_ok,
                    'last_tested_at' => $connection->last_tested_at?->toIso8601String(),
                ]),
            'recentDestinations' => Destination::query()->latest()->take(5)->get()
                ->map(fn (Destination $destination): array => [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'type' => $destination->type,
                    'last_test_ok' => $destination->last_test_ok,
                    'last_tested_at' => $destination->last_tested_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Total + last-test status breakdown for a testable model.
     *
     * @param  class-string<Model>  $model
     * @return array{total: int, passing: int, failing: int, untested: int}
     */
    private function breakdown(string $model): array
    {
        return [
            'total' => $model::query()->count(),
            'passing' => $model::query()->where('last_test_ok', true)->count(),
            'failing' => $model::query()->where('last_test_ok', false)->count(),
            'untested' => $model::query()->whereNull('last_test_ok')->count(),
        ];
    }
}
