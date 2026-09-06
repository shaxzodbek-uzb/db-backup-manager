<?php

namespace Tests\Feature;

use App\Models\BackupPlan;
use App\Models\BackupRun;
use App\Models\Destination;
use App\Services\Backup\BackupHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every other test here asks what a run did. These ask what no run did.
 *
 * A backup that fails leaves a row saying it failed. A backup that stops
 * happening leaves nothing — and that silence is what the alert reads.
 */
class BackupHealthTest extends TestCase
{
    use RefreshDatabase;

    /** Plans run nightly at 23:15 UTC; "now" is the following midday. */
    private const NOW = '2026-09-07 12:00:00';

    /**
     * `created_at` is pinned rather than left to the clock: a plan younger
     * than the slot it is measured against has not missed anything, so a
     * factory default of "now" would quietly change what these tests assert
     * depending on the day they run.
     */
    private function nightlyPlan(array $attributes = []): BackupPlan
    {
        return BackupPlan::factory()->create(array_merge([
            'name' => 'blaze-core nightly',
            'cron' => '15 23 * * *',
            'timezone' => 'UTC',
            'enabled' => true,
            'created_at' => Carbon::parse('2026-09-01 00:00:00'),
        ], $attributes));
    }

    private function recordRun(BackupPlan $plan, string $status, string $startedAt): BackupRun
    {
        return BackupRun::factory()->create([
            'backup_plan_id' => $plan->id,
            'connection_id' => $plan->connection_id,
            'status' => $status,
            'started_at' => Carbon::parse($startedAt),
            'finished_at' => Carbon::parse($startedAt)->addMinutes(2),
        ]);
    }

    private function checkAt(string $now = self::NOW): array
    {
        return app(BackupHealthChecker::class)->check(Carbon::parse($now));
    }

    public function test_a_plan_that_ran_after_its_last_due_slot_is_healthy(): void
    {
        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'success', '2026-09-06 23:15:30');

        $health = $this->checkAt();

        $this->assertTrue($health[0]->healthy());
        $this->assertFalse($health[0]->degraded());
    }

    public function test_a_plan_whose_last_good_run_predates_the_due_slot_is_stale(): void
    {
        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'success', '2026-09-05 23:15:30');

        $health = $this->checkAt();

        $this->assertFalse($health[0]->healthy());
        $this->assertSame(36, $health[0]->hoursSinceLastGood(Carbon::parse(self::NOW)));
    }

    public function test_a_plan_that_never_ran_is_stale(): void
    {
        $this->nightlyPlan();

        $health = $this->checkAt();

        $this->assertFalse($health[0]->healthy());
        $this->assertNull($health[0]->lastGood);
    }

    /**
     * Two databases here are larger than Telegram will accept, so those plans
     * report `partial` every single night. Treating that as a failure would
     * page about a known limit until nobody reads the alerts at all.
     */
    public function test_a_partial_run_counts_as_delivered_but_is_reported_as_degraded(): void
    {
        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'partial', '2026-09-06 23:15:30');

        $health = $this->checkAt();

        $this->assertTrue($health[0]->healthy());
        $this->assertTrue($health[0]->degraded());
    }

    public function test_a_failed_run_does_not_count_as_a_backup(): void
    {
        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'failed', '2026-09-06 23:15:30');

        $health = $this->checkAt();

        $this->assertFalse($health[0]->healthy());
        $this->assertSame('failed', $health[0]->latest?->status);
    }

    /**
     * The grace period is what separates "running late" from "did not happen":
     * an hour after the slot, a plan with no run yet is not yet news.
     */
    public function test_a_plan_inside_its_grace_period_is_not_yet_stale(): void
    {
        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'success', '2026-09-05 23:15:30');

        $health = $this->checkAt('2026-09-07 00:15:00');

        $this->assertTrue($health[0]->healthy());
    }

    /**
     * A plan is at its most watched on the day it is created, and that is the
     * day it has no history at all.
     */
    public function test_a_plan_created_after_the_last_slot_has_not_missed_it(): void
    {
        $this->nightlyPlan(['created_at' => Carbon::parse('2026-09-07 09:00:00')]);

        $health = $this->checkAt();

        $this->assertTrue($health[0]->healthy());
        $this->assertNull($health[0]->lastGood);
    }

    public function test_a_plan_older_than_the_slot_with_no_runs_is_stale(): void
    {
        $this->nightlyPlan(['created_at' => Carbon::parse('2026-09-01 09:00:00')]);

        $this->assertFalse($this->checkAt()[0]->healthy());
    }

    public function test_a_disabled_plan_is_not_watched(): void
    {
        $this->nightlyPlan(['enabled' => false]);

        $this->assertSame([], $this->checkAt());
    }

    public function test_the_command_alerts_over_telegram_and_exits_non_zero(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Destination::factory()->telegram()->create();

        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'success', '2026-09-05 23:15:30');

        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->artisan('backups:check-health')->assertFailed();

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'blaze-core nightly')
                && str_contains((string) $request['text'], 'Last good backup');
        });
    }

    public function test_a_healthy_run_of_the_command_sends_nothing_and_succeeds(): void
    {
        Http::fake();
        Destination::factory()->telegram()->create();

        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'success', '2026-09-06 23:15:30');

        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->artisan('backups:check-health')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_no_enabled_plans_at_all_is_itself_a_failure(): void
    {
        Http::fake();

        $this->artisan('backups:check-health')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_no_alert_reports_without_sending(): void
    {
        Http::fake();
        Destination::factory()->telegram()->create();

        $plan = $this->nightlyPlan();
        $this->recordRun($plan, 'success', '2026-09-05 23:15:30');

        Carbon::setTestNow(Carbon::parse(self::NOW));

        $this->artisan('backups:check-health --no-alert')->assertFailed();

        Http::assertNothingSent();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
