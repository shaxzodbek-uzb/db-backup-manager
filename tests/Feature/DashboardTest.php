<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_reports_connection_and_destination_stats(): void
    {
        $this->connectionWithStatus(true);
        $this->connectionWithStatus(false);
        $this->connectionWithStatus(null);
        Destination::factory()->create()->forceFill(['last_test_ok' => true])->save();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('stats.connections.total', 3)
                ->where('stats.connections.passing', 1)
                ->where('stats.connections.failing', 1)
                ->where('stats.connections.untested', 1)
                ->where('stats.destinations.total', 1)
                ->where('stats.destinations.passing', 1)
                ->has('recentConnections', 3)
                ->has('recentDestinations', 1));
    }

    public function test_dashboard_recent_lists_do_not_leak_secrets(): void
    {
        Connection::factory()->create();
        Destination::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('recentConnections.0.password')
                ->missing('recentDestinations.0.config'));
    }

    private function connectionWithStatus(?bool $ok): Connection
    {
        $connection = Connection::factory()->create();

        if ($ok !== null) {
            $connection->forceFill(['last_test_ok' => $ok, 'last_tested_at' => now()])->save();
        }

        return $connection;
    }
}
