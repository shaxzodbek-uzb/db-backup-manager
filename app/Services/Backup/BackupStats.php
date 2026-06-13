<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;

class BackupStats
{
    /**
     * Daily success/failure counts (and bytes) for the last $days days, oldest
     * first, with empty days filled in so the chart has a continuous axis.
     *
     * @return list<array{date: string, success: int, failed: int, bytes: int}>
     */
    public function daily(int $days = 14): array
    {
        $start = Carbon::today()->subDays($days - 1);

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $buckets[$date] = ['date' => $date, 'success' => 0, 'failed' => 0, 'bytes' => 0];
        }

        $runs = BackupRun::query()
            ->where('created_at', '>=', $start->copy()->startOfDay())
            ->get(['status', 'total_bytes', 'created_at']);

        foreach ($runs as $run) {
            $date = $run->created_at?->toDateString();
            if ($date === null || ! isset($buckets[$date])) {
                continue;
            }

            if ($run->status === 'success') {
                $buckets[$date]['success']++;
            } elseif (in_array($run->status, ['failed', 'partial'], true)) {
                $buckets[$date]['failed']++;
            } else {
                continue; // running / pending — not a completed run
            }

            $buckets[$date]['bytes'] += (int) $run->total_bytes;
        }

        return array_values($buckets);
    }
}
