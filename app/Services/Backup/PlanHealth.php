<?php

namespace App\Services\Backup;

use App\Models\BackupPlan;
use App\Models\BackupRun;
use Carbon\CarbonInterface;

/**
 * One plan's answer to the only question worth asking of a backup system
 * between disasters: did last night happen?
 */
class PlanHealth
{
    public function __construct(
        public readonly BackupPlan $plan,
        /** The scheduled time this plan should already have finished a run for. */
        public readonly CarbonInterface $expectedSince,
        /** The newest run that took and delivered the dumps, whenever it was. */
        public readonly ?BackupRun $lastGood,
        /** The newest run of any kind, which is what explains a failure. */
        public readonly ?BackupRun $latest,
    ) {}

    /**
     * Healthy means the last good run covers the slot that has already come
     * and gone — not merely that a good run exists somewhere in the past.
     */
    public function healthy(): bool
    {
        return $this->delivered() || $this->newerThanTheSlot();
    }

    /**
     * A plan written after the slot passed cannot have missed it.
     *
     * Without this, every plan announces itself as a failed backup between
     * being created and running for the first time — an alert that is wrong on
     * the one day somebody is definitely watching.
     */
    public function newerThanTheSlot(): bool
    {
        return $this->plan->created_at?->greaterThan($this->expectedSince) === true;
    }

    private function delivered(): bool
    {
        return $this->lastGood?->started_at !== null
            && $this->lastGood->started_at->greaterThanOrEqualTo($this->expectedSince);
    }

    /**
     * Delivered, but not everything reached every destination — one database
     * of several failed to send. Worth reading, not worth waking anyone.
     */
    public function degraded(): bool
    {
        return $this->healthy() && $this->lastGood?->status === 'partial';
    }

    public function hoursSinceLastGood(CarbonInterface $now): ?int
    {
        $at = $this->lastGood?->started_at;

        return $at === null ? null : (int) $at->diffInHours($now);
    }
}
