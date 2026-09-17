<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * What the scheduler does when a run dies without cleaning up after itself.
 *
 * `withoutOverlapping()` with no argument holds its mutex for a day. A run that
 * is killed mid-flight therefore takes the next twenty-four hours of its own
 * command with it, and takes them silently: a command the scheduler skips for
 * the mutex writes nothing anywhere, so the log reads "No scheduled commands
 * are ready to run" and looks perfectly healthy.
 *
 * That is not a hypothetical. On 2026-09-16 the mutex on `backups:dispatch-due`
 * was stuck, `schedule:list` showed "Has Mutex" against it, and the night's
 * backup window for all six plans passed with the dispatcher blocked. Nothing
 * failed. Nothing was recorded. The backups simply did not happen.
 *
 * So the expiry is not a detail of these two lines — it is the difference
 * between a crash costing one window and a crash costing a day.
 */
class ScheduleMutexTest extends TestCase
{
    /** Longer than any of these commands can legitimately take to finish. */
    private const MAX_EXPIRY_MINUTES = 60;

    public function test_every_overlap_guarded_command_releases_its_mutex_within_the_hour(): void
    {
        $guarded = array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => $event->withoutOverlapping,
        );

        $this->assertNotEmpty(
            $guarded,
            'No scheduled command uses withoutOverlapping() — this test is guarding nothing.',
        );

        foreach ($guarded as $event) {
            $this->assertLessThanOrEqual(
                self::MAX_EXPIRY_MINUTES,
                $event->expiresAt,
                "[{$event->command}] holds its mutex for {$event->expiresAt} minutes. "
                .'Laravel defaults to 1440 (a full day), which is how a single killed run '
                .'silences a command until the next afternoon. Pass a number to '
                .'withoutOverlapping().',
            );
        }
    }

    public function test_the_dispatcher_and_the_watchdog_are_both_guarded_and_both_expire(): void
    {
        $byCommand = [];

        foreach (app(Schedule::class)->events() as $event) {
            foreach (['backups:dispatch-due', 'backups:check-health'] as $needle) {
                if (str_contains((string) $event->command, $needle)) {
                    $byCommand[$needle] = $event;
                }
            }
        }

        foreach (['backups:dispatch-due', 'backups:check-health'] as $needle) {
            $this->assertArrayHasKey($needle, $byCommand, "{$needle} is not scheduled at all.");
            $this->assertTrue($byCommand[$needle]->withoutOverlapping, "{$needle} is not overlap-guarded.");
            $this->assertNotSame(
                1440,
                $byCommand[$needle]->expiresAt,
                "{$needle} is back on Laravel's day-long default expiry.",
            );
        }
    }
}
