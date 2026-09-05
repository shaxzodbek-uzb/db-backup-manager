<?php

namespace App\Services\Backup;

use App\Models\BackupArtifact;
use App\Models\BackupPlan;
use App\Services\Destination\DestinationManager;
use App\Services\Destination\DestinationUploader;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a plan's retention policy by deleting the dump *files* of runs that
 * fall outside the window. Run records are kept for history (status, size, log);
 * only the artifacts (and their soft-delete markers) are pruned.
 */
class RetentionManager
{
    public function __construct(
        private DestinationManager $destinations,
        private DestinationUploader $uploader,
    ) {}

    public function prune(BackupPlan $plan): void
    {
        if ($plan->retention_copies === null && $plan->retention_days === null) {
            return;
        }

        $runs = $plan->runs()
            ->whereIn('status', ['success', 'partial'])
            ->orderByDesc('id')
            ->with('artifacts.destination')
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
                $this->forget($artifact);
            }
        }
    }

    /**
     * Deletes one artifact's bytes, wherever they live.
     *
     * The row is dropped even when the bytes could not be removed. Keeping it
     * would mean the same failing delete is retried on every run forever, and
     * the row's real purpose — telling you a backup exists — is already false
     * once retention has decided it should not.
     */
    private function forget(BackupArtifact $artifact): void
    {
        try {
            if ($artifact->isRemote()) {
                $destination = $artifact->destination;

                if ($destination !== null && ! $this->uploader->supportsDeletion($destination)) {
                    // Telegram: a bot can only delete its own messages for 48
                    // hours, so the document stays in the chat. Dropping the row
                    // is still right — retention has decided this copy is not
                    // one we track any more.
                    Log::info('Retention left a document in place: its destination cannot delete.', [
                        'artifact_id' => $artifact->id,
                        'destination' => $destination->name,
                    ]);
                } elseif ($destination !== null) {
                    $this->destinations->disk($destination)->delete($artifact->path);
                } else {
                    // The destination record was deleted out from under us, so
                    // the object's location is no longer knowable from here.
                    Log::warning('Retention could not reach a remote artifact: its destination is gone.', [
                        'artifact_id' => $artifact->id,
                        'path' => $artifact->path,
                    ]);
                }
            } else {
                $full = storage_path('app/'.$artifact->path);

                if (is_file($full)) {
                    @unlink($full);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Retention failed to delete a backup artifact.', [
                'artifact_id' => $artifact->id,
                'path' => $artifact->path,
                'error' => $e->getMessage(),
            ]);
        }

        $artifact->delete();
    }
}
