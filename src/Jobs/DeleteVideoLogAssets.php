<?php

namespace Lithium\VideoLogs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Models\VideoLog;

class DeleteVideoLogAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;            // try harder — orphaned files cost money

    public int $backoff = 60;

    public function __construct(public int $videoLogId) {}

    public function handle(VideoStorageProvider $videoLogStorageProvider): void
    {
        // Fetch INCLUDING soft-deleted — the record was soft-deleted before this ran.
        $videoLog = VideoLog::withTrashed()->find($this->videoLogId);

        if (! $videoLog) {
            return;   // already fully gone, nothing to clean
        }

        // Delete provider assets. deleteAssets is idempotent — deleting
        // already-absent files is a no-op, so retries are safe.
        $videoLogStorageProvider->deleteAssets($videoLog);

        // Now that the files are gone, hard-delete the record for good.
        $videoLog->forceDelete();
    }

    public function failed(\Throwable $e): void
    {
        // Deliberately do NOT hard-delete the record on failure.
        // Keeping the soft-deleted row (with its provider_meta keys) means
        // we still know which files to clean up on a later manual retry.
        \Log::error("Failed to delete video videoLog assets for {$this->videoLogId}", [
            'exception' => $e->getMessage(),
        ]);
    }
}
