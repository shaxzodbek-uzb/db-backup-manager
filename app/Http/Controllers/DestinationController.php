<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDestinationRequest;
use App\Http\Requests\UpdateDestinationRequest;
use App\Models\Destination;
use App\Services\Destination\DestinationTester;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DestinationController extends Controller
{
    public function __construct(private DestinationTester $tester) {}

    public function index(): Response
    {
        return Inertia::render('destinations/Index', [
            'destinations' => Destination::query()
                ->latest()
                ->get()
                ->map(fn (Destination $destination): array => $this->present($destination)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('destinations/Form', ['destination' => null]);
    }

    public function store(StoreDestinationRequest $request): RedirectResponse
    {
        $type = $request->validated('type');

        Destination::create([
            'name' => $request->validated('name'),
            'type' => $type,
            'config' => $this->buildConfig($type, $request->validated('config', []), null),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Destination created.')]);

        return to_route('destinations.index');
    }

    public function edit(Destination $destination): Response
    {
        return Inertia::render('destinations/Form', ['destination' => $this->present($destination)]);
    }

    public function update(UpdateDestinationRequest $request, Destination $destination): RedirectResponse
    {
        $type = $request->validated('type');

        $destination->update([
            'name' => $request->validated('name'),
            'type' => $type,
            'config' => $this->buildConfig($type, $request->validated('config', []), $destination),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Destination updated.')]);

        return to_route('destinations.index');
    }

    public function destroy(Destination $destination): RedirectResponse
    {
        $destination->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Destination deleted.')]);

        return to_route('destinations.index');
    }

    public function test(Destination $destination): RedirectResponse
    {
        $result = $this->tester->test($destination);

        $destination->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $result->ok,
            'last_test_error' => $result->error,
        ])->save();

        Inertia::flash('toast', $result->ok
            ? ['type' => 'success', 'message' => __('Destination reachable (:ms ms).', ['ms' => $result->latencyMs])]
            : ['type' => 'error', 'message' => __('Destination test failed: :error', ['error' => $result->error])]);

        return back();
    }

    /**
     * Merge submitted config with the stored one, keeping only the keys for the
     * chosen type and preserving secrets left blank on update.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function buildConfig(string $type, array $input, ?Destination $existing): array
    {
        $secretKeys = Destination::secretKeysFor($type);
        $config = [];

        foreach (Destination::CONFIG_KEYS[$type] ?? [] as $key) {
            $value = $input[$key] ?? null;

            if (in_array($key, $secretKeys, true) && ! filled($value)) {
                // Blank secret: keep the stored value (update) or omit (create).
                if ($existing && filled($existing->config[$key] ?? null)) {
                    $config[$key] = $existing->config[$key];
                }

                continue;
            }

            $config[$key] = match ($key) {
                'use_path_style' => (bool) ($value ?? false),
                default => is_string($value) ? trim($value) : $value,
            };
        }

        return $config;
    }

    /**
     * Frontend-safe representation — secret config values are replaced by
     * "is it set" flags and never leave the server.
     *
     * @return array<string, mixed>
     */
    private function present(Destination $destination): array
    {
        return [
            'id' => $destination->id,
            'name' => $destination->name,
            'type' => $destination->type,
            'config' => $destination->safeConfig(),
            'secrets' => $destination->secretFlags(),
            'last_tested_at' => $destination->last_tested_at?->toIso8601String(),
            'last_test_ok' => $destination->last_test_ok,
            'last_test_error' => $destination->last_test_error,
        ];
    }
}
