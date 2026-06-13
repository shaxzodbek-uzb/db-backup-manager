<?php

namespace App\Services\Backup;

use App\Models\BackupPlan;

/**
 * Applies a plan's retention policy by deleting the dump *files* of runs that
 * fall outside the window. Run records are kept for history (status, size, log);
 * only the artifacts (and their soft-delete markers) are pruned.
 */
class RetentionManager
{
    public function prune(BackupPlan $plan): void
    {
        if ($plan->retention_copies === null && $plan->retention_days === null) {
            return;
        }

        $runs = $plan->runs()
            ->whereIn('status', ['success', 'partial'])
            ->orderByDesc('id')
            ->with('artifacts')
            ->get();

        $keepIdsByCount = $plan->retention_copies !== null
            ? $runs->take($plan->retention_copies)->pluck('id')->all()
            : [];

        $cutoff = $plan->retention_days !== null
            ? now()->subDays($plan->retention_days)
            : null;

        foreach ($runs as $run) {
            $keptByCount = $plan->retention_copies !== null && in_array($run->id, $keepIdsByCount, true);
            $keptByAge = $cutoff !== null && $run->created_at !== null && $run->created_at->greaterThanOrEqualTo($cutoff);

            if ($keptByCount || $keptByAge) {
                continue;
            }

            foreach ($run->artifacts as $artifact) {
                $full = storage_path('app/'.$artifact->path);
                if (is_file($full)) {
                    @unlink($full);
                }
                $artifact->delete();
            }
        }
    }
}
