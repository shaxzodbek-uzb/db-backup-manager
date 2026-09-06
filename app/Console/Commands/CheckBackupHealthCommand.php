<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupHealthChecker;
use App\Services\Backup\PlanHealth;
use App\Services\Destination\TelegramMessenger;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Says out loud when a plan's backups stopped arriving.
 *
 * Everything else here reports what a run did. Nothing reported what no run
 * did, and a backup that quietly stops happening leaves no failed job, no error
 * row and no red screen — only an older and older newest copy that nobody is
 * looking at. Exits non-zero as well as messaging, so cron mail and any
 * external monitor see it without trusting the chat to have been read.
 */
class CheckBackupHealthCommand extends Command
{
    protected $signature = 'backups:check-health
        {--no-alert : Report to the console only; send nothing to Telegram}';

    protected $description = 'Check that every enabled plan has a recent finished backup, and say so when one does not';

    public function handle(BackupHealthChecker $checker, TelegramMessenger $messenger): int
    {
        $now = Date::now();
        $health = $checker->check($now);

        if ($health === []) {
            $this->warn('No enabled backup plans — nothing is being backed up.');

            return self::FAILURE;
        }

        foreach ($health as $plan) {
            $this->line($this->describe($plan, $now));
        }

        $stale = array_values(array_filter($health, fn (PlanHealth $plan): bool => ! $plan->healthy()));

        if ($stale === []) {
            $this->info('Every plan has a recent backup.');

            return self::SUCCESS;
        }

        if (! $this->option('no-alert')) {
            $this->sendAlert($messenger, $this->alertText($stale, $health, $now));
        }

        return self::FAILURE;
    }

    private function sendAlert(TelegramMessenger $messenger, string $text): void
    {
        $destination = $messenger->alertDestination();

        if ($destination === null) {
            // Not fatal on its own: the non-zero exit still carries the news to
            // cron. Said out loud because a health check nobody can hear is the
            // failure it was written to prevent, one level up.
            $this->error('No Telegram destination configured — the alert could not be sent.');

            return;
        }

        try {
            $messenger->send($destination, $text);
            $this->info("Alert sent to [{$destination->name}].");
        } catch (Throwable $exception) {
            Log::error('Backup health alert could not be delivered.', [
                'error' => $exception->getMessage(),
            ]);

            $this->error('Alert could not be delivered: '.$exception->getMessage());
        }
    }

    private function describe(PlanHealth $plan, CarbonInterface $now): string
    {
        $hours = $plan->hoursSinceLastGood($now);
        $last = $plan->lastGood === null
            ? 'never'
            : $plan->lastGood->started_at?->format('Y-m-d H:i').' UTC'." ({$hours}h ago)";

        return match (true) {
            ! $plan->healthy() => "✗ {$plan->plan->name}: last good backup {$last}, expected one since "
                .$plan->expectedSince->format('Y-m-d H:i').' UTC',
            $plan->degraded() => "~ {$plan->plan->name}: {$last}, but not every dump reached every destination",
            default => "✓ {$plan->plan->name}: {$last}",
        };
    }

    /**
     * @param  list<PlanHealth>  $stale
     * @param  list<PlanHealth>  $all
     */
    private function alertText(array $stale, array $all, CarbonInterface $now): string
    {
        $lines = [
            count($stale) === 1
                ? '🚨 A backup plan has stopped running'
                : '🚨 '.count($stale).' backup plans have stopped running',
            '',
        ];

        foreach ($stale as $plan) {
            $lines[] = '📋 '.$plan->plan->name;
            $lines[] = '🕐 Due since: '.$plan->expectedSince->format('Y-m-d H:i').' UTC';

            $hours = $plan->hoursSinceLastGood($now);

            $lines[] = $plan->lastGood === null
                ? '❌ No successful backup has ever been recorded for this plan.'
                : '❌ Last good backup: '.$plan->lastGood->started_at?->format('Y-m-d H:i')
                    ." UTC ({$hours}h ago)";

            if ($plan->latest !== null && $plan->latest->status === 'failed') {
                $lines[] = '   Last attempt failed: '.$this->trim((string) $plan->latest->error);
            }

            $lines[] = '';
        }

        $degraded = array_filter($all, fn (PlanHealth $plan): bool => $plan->degraded());

        if ($degraded !== []) {
            $lines[] = 'ℹ️ Delivered but incomplete: '
                .implode(', ', array_map(fn (PlanHealth $plan): string => $plan->plan->name, $degraded));
            $lines[] = '';
        }

        $lines[] = '👉 '.config('app.url');

        return implode("\n", $lines);
    }

    private function trim(string $error): string
    {
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');

        return mb_strlen($error) > 200 ? mb_substr($error, 0, 200).'…' : $error;
    }
}
