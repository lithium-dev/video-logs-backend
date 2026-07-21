<?php

namespace Lithium\VideoLogs\Contracts;

use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Support\PlaybackLink;
use Lithium\VideoLogs\Support\UploadTarget;
use Lithium\VideoLogs\Support\WebhookRequest;

interface VideoStorageProvider
{
    /**
     * Issue whatever the client needs to upload bytes directly to storage.
     * Called after the VideoLog record exists but before any bytes are sent.
     * MUST NOT block on the upload itself — it only hands back the target.
     */
    public function createUpload(VideoLog $videoLog): UploadTarget;

    /**
     * Mark the raw upload as complete and record provider identifiers.
     * Called when the client reports the upload finished.
     * For multipart uploads, $payload may include 'parts' => [{part_number, etag}].
     * Writes provider_meta (e.g. source key / asset id) onto the videoLog.
     * MUST NOT transcode inline — that's dispatchProcessing's job.
     *
     * @param  array{parts?: array<int, array{part_number: int, etag: string}>}  $payload
     */
    public function finalizeUpload(VideoLog $videoLog, array $payload): void;

    /**
     * Presign upload URLs for specific multipart part numbers.
     * Used to refresh expired part URLs during a long upload or when resuming.
     * Returns [{part_number, url}, ...]. Providers without multipart return [].
     *
     * @param  array<int, int>  $partNumbers
     * @return array<int, array{part_number: int, url: string}>
     */
    public function signUploadParts(VideoLog $videoLog, array $partNumbers): array;

    /**
     * Report which multipart parts have already been uploaded to storage.
     * Used by the client to skip completed parts when resuming after a reload.
     * Returns {multipart, upload_id, part_size, parts: [{part_number, etag, size}]}.
     *
     * @return array{
     *     multipart: bool,
     *     upload_id: ?string,
     *     part_size: ?int,
     *     part_count: ?int,
     *     parts: array<int, array{part_number: int, etag: string, size: int|null}>
     * }
     */
    public function uploadStatus(VideoLog $videoLog): array;

    /**
     * Abort an in-progress multipart upload and free provider-side partial parts.
     * Idempotent: aborting when there is no multipart upload is a no-op.
     */
    public function abortUpload(VideoLog $videoLog): void;

    /**
     * Kick off transcoding / asset creation asynchronously.
     * Returns immediately; completion arrives later via handleWebhook.
     * Idempotent: safe to call twice for the same videoLog without double-processing.
     */
    public function dispatchProcessing(VideoLog $videoLog): void;

    /**
     * Produce a time-limited playback link for a ready video.
     * Returns your own PlaybackLink shape, not a raw provider URL.
     * MUST throw / signal clearly if the videoLog isn't in a playable state.
     */
    public function playbackLink(VideoLog $videoLog, int $ttlSeconds = 3600): PlaybackLink;

    /**
     * Produce a time-limited URL for the poster/thumbnail image, if one exists.
     * Returns null when no poster is available rather than throwing.
     */
    public function posterUrl(VideoLog $videoLog, int $ttlSeconds = 3600): ?string;

    /**
     * Remove ALL provider-side assets for this videoLog (source, renditions, poster).
     * Called from the async delete job, not inline.
     * Idempotent: deleting already-deleted assets is a no-op, not an error.
     */
    public function deleteAssets(VideoLog $videoLog): void;

    /**
     * Translate a raw provider webhook payload into internal state changes.
     * Verifies the payload's authenticity (signature) before acting.
     * Resolves which VideoLog the event refers to via provider_meta.
     * Updates status and fires VideoLogReady / VideoLogFailed as appropriate.
     */
    public function handleWebhook(WebhookRequest $request): void;

    /**
     * Pull the current processing status directly from the provider and
     * reconcile the VideoLog's state with it. This is the pull-based
     * counterpart to handleWebhook, useful when the webhook isn't configured
     * or failed to arrive.
     * Updates status and fires VideoLogReady / VideoLogFailed as appropriate.
     */
    public function checkStatus(VideoLog $videoLog): void;
}
