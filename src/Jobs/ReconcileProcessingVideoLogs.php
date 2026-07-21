<?php

namespace Lithium\VideoLogs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Models\VideoLog;

/**
 * Sweeps video logs that are stuck in the "processing" state and stops them
 * from hanging indefinitely — the backend counterpart to the frontend poller.
 *
 * Two things can leave a log stuck on "processing":
 *   1. The provider finished but its completion webhook never arrived (not
 *      configured, dropped, etc). We fix this by pulling the status directly
 *      from the provider (checkStatus), which flips it to ready/failed.
 *   2. The provider genuinely never finished. Past a hard timeout we give up
 *      and mark the log failed so the UI stops waiting on it forever.
 *
 * Runs on a schedule (see VideoLogsServiceProvider) and only touches logs old
 * enough to have plausibly settled, so it never races an in-flight transcode.
 */
class ReconcileProcessingVideoLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(VideoStorageProvider $videoStorageProvider): void
    {
        $reconcileAfterMinutes = (int) config('video-logs.processing.reconcile_after_minutes', 5);
        $timeoutMinutes = (int) config('video-logs.processing.timeout_minutes', 60);

        $reconcileBefore = now()->subMinutes($reconcileAfterMinutes);
        $timeoutBefore = now()->subMinutes($timeoutMinutes);

        VideoLog::query()
            ->where('status', 'processing')
            ->where('updated_at', '<=', $reconcileBefore)
            ->orderBy('id')
            ->chunkById(100, function ($videoLogs) use ($videoStorageProvider, $timeoutBefore) {
                foreach ($videoLogs as $videoLog) {
                    $this->reconcile($videoLog, $videoStorageProvider, $timeoutBefore);
                }
            });
    }

    private function reconcile(
        VideoLog $videoLog,
        VideoStorageProvider $videoStorageProvider,
        \DateTimeInterface $timeoutBefore,
    ): void {
        // Ask the provider what really happened. If the webhook was just
        // missed, this resolves the log to ready/failed.
        $videoStorageProvider->checkStatus($videoLog);
        $videoLog->refresh();

        // Provider confirmed a terminal state — nothing left to do.
        if ($videoLog->status !== 'processing') {
            return;
        }

        // Still processing and past the hard timeout: stop waiting on it.
        // updated_at hasn't moved since it entered "processing" (checkStatus
        // left it untouched), so it's a fair proxy for how long it's been stuck.
        if ($videoLog->updated_at <= $timeoutBefore) {
            $videoLog->recordFailure('processing_timeout', [
                'message' => "Video log stayed in processing past the {$this->timeoutLabel()} timeout.",
            ]);
        }
    }

    private function timeoutLabel(): string
    {
        $timeoutMinutes = (int) config('video-logs.processing.timeout_minutes', 60);

        return $timeoutMinutes.' minute'.($timeoutMinutes === 1 ? '' : 's');
    }
}
