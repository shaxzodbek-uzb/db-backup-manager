<?php

namespace App\Services\Backup;

use App\Models\BackupPlan;
use App\Models\BackupRun;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\Date;

/**
 * Reads whether each plan's backups are still arriving.
 *
 * A failed run leaves a row saying it failed, and the screens show it. A run
 * that never started leaves nothing — no row, no error, no failed job to count
 * — and that absence is the shape the outages have taken: a schedule pointing
 * at the wrong application, a worker running last week's code. Nothing here
 * reads the runs that happened; it reads the ones that should have.
 */
class BackupHealthChecker
{
    /**
     * Statuses that mean the dumps were taken and delivered.
     *
     * `partial` counts, deliberately. It is what a plan reports when one
     * database of many could not be sent, and on this installation that is a
     * standing condition — Telegram refuses documents over 50 MB and two
     * databases have outgrown it. An alert that fires every night about a
     * known limit is one people learn to close without reading, so partial is
     * reported rather than alerted on.
     *
     * @var list<string>
     */
    public const DELIVERED = ['success', 'partial'];

    public function __construct(private readonly ?int $graceHours = null) {}

    /**
     * @return list<PlanHealth>
     */
    public function check(?CarbonInterface $now = null): array
    {
        $now ??= Date::now();

        return BackupPlan::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get()
            ->map(fn (BackupPlan $plan): PlanHealth => $this->checkPlan($plan, $now))
            ->all();
    }

    public function checkPlan(BackupPlan $plan, CarbonInterface $now): PlanHealth
    {
        return new PlanHealth(
            plan: $plan,
            expectedSince: $this->lastSlotPastGrace($plan, $now),
            lastGood: $this->newestRun($plan, self::DELIVERED),
            latest: $this->newestRun($plan),
        );
    }

    /**
     * The most recent scheduled time whose grace period has already run out.
     *
     * Measuring staleness as "a fixed number of hours ago" gets one of the two
     * cases wrong depending on the hour the check runs: it either pages about a
     * plan that is merely running late, or stays quiet through a whole missed
     * night. The plan's own cron already says when the backup was due.
     */
    private function lastSlotPastGrace(BackupPlan $plan, CarbonInterface $now): CarbonInterface
    {
        $deadline = $now->subHours($this->graceHours());

        $slot = (new CronExpression($plan->cron))
            ->getPreviousRunDate($deadline, 0, true, $plan->timezone);

        // Back to UTC before it is compared with stored timestamps: the cron is
        // evaluated in the plan's zone, and started_at is not.
        return Date::instance($slot)->utc();
    }

    /**
     * @param  list<string>|null  $statuses
     */
    private function newestRun(BackupPlan $plan, ?array $statuses = null): ?BackupRun
    {
        return BackupRun::query()
            ->where('backup_plan_id', $plan->id)
            ->when($statuses !== null, fn ($query) => $query->whereIn('status', $statuses))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    private function graceHours(): int
    {
        return max(0, $this->graceHours ?? (int) config('backup.health.grace_hours'));
    }
}
