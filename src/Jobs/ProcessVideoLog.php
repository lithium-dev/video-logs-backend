<?php

namespace Lithium\VideoLogs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Models\VideoLog;

class ProcessVideoLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;            // retry up to 3 times on failure

    public int $backoff = 30;         // wait 30s between retries

    public function __construct(public int $videoLogId) {}

    public function handle(VideoStorageProvider $videoStorageProvider): void
    {
        // Re-fetch fresh. Don't trust a serialized copy from when the job was queued.
        $videoLog = VideoLog::find($this->videoLogId);

        // The videoLog may have been deleted between queueing and running.
        if (! $videoLog || $videoLog->trashed()) {
            return;
        }

        // Only process logs that are actually awaiting processing.
        if ($videoLog->status !== 'processing') {
            return;   // already handled, or in a state that shouldn't transcode
        }

        // Hand off to the provider. Its dispatchProcessing is itself idempotent
        // (won't submit a second MediaConvert job if one already exists).
        $videoStorageProvider->dispatchProcessing($videoLog);
    }

    // Called when all retries are exhausted.
    public function failed(\Throwable $e): void
    {
        $videoLog = VideoLog::find($this->videoLogId);
        if ($videoLog && ! $videoLog->trashed()) {
            $videoLog->recordFailure('processing_dispatch_failed', [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
