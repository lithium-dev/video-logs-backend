<?php

namespace Lithium\VideoLogs\Tests\Fixtures;

use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Support\PlaybackLink;
use Lithium\VideoLogs\Support\UploadTarget;
use Lithium\VideoLogs\Support\WebhookRequest;

class FakeVideoStorageProvider implements VideoStorageProvider
{
    /** @var array<int, VideoLog> */
    public array $createUploadCalls = [];

    /** @var array<int, array{videoLog: VideoLog, payload: array}> */
    public array $finalizeUploadCalls = [];

    /** @var array<int, array{videoLog: VideoLog, partNumbers: array<int, int>}> */
    public array $signUploadPartsCalls = [];

    /** @var array<int, VideoLog> */
    public array $uploadStatusCalls = [];

    /** @var array<int, VideoLog> */
    public array $abortUploadCalls = [];

    /** @var array<int, VideoLog> */
    public array $dispatchProcessingCalls = [];

    /** @var array<int, array{videoLog: VideoLog, ttlSeconds: int}> */
    public array $playbackLinkCalls = [];

    /** @var array<int, array{videoLog: VideoLog, ttlSeconds: int}> */
    public array $posterUrlCalls = [];

    /** @var array<int, VideoLog> */
    public array $deleteAssetsCalls = [];

    /** @var array<int, WebhookRequest> */
    public array $handleWebhookCalls = [];

    /** @var array<int, VideoLog> */
    public array $checkStatusCalls = [];

    public function createUpload(VideoLog $videoLog): UploadTarget
    {
        $this->createUploadCalls[] = $videoLog;

        $videoLog->update([
            'provider' => 'fake',
            'status' => 'uploading',
            'provider_metadata' => array_merge($videoLog->provider_metadata ?? [], [
                'source_key' => 'fake/source/'.$videoLog->id,
            ]),
        ]);

        return new UploadTarget(
            method: 'PUT',
            url: 'https://example.test/upload/'.$videoLog->id,
            headers: ['Content-Type' => 'video/mp4'],
            expiresIn: 3600,
        );
    }

    public function finalizeUpload(VideoLog $videoLog, array $payload): void
    {
        $this->finalizeUploadCalls[] = [
            'videoLog' => $videoLog,
            'payload' => $payload,
        ];

        $videoLog->update(['status' => 'processing']);
    }

    /**
     * @param  array<int, int>  $partNumbers
     * @return array<int, array{part_number: int, url: string}>
     */
    public function signUploadParts(VideoLog $videoLog, array $partNumbers): array
    {
        $this->signUploadPartsCalls[] = [
            'videoLog' => $videoLog,
            'partNumbers' => $partNumbers,
        ];

        return array_map(function (int $partNumber) use ($videoLog) {
            return [
                'part_number' => $partNumber,
                'url' => 'https://example.test/upload/'.$videoLog->id.'/part/'.$partNumber,
            ];
        }, array_map('intval', $partNumbers));
    }

    /**
     * @return array{
     *     multipart: bool,
     *     upload_id: ?string,
     *     part_size: ?int,
     *     part_count: ?int,
     *     parts: array<int, array{part_number: int, etag: string, size: int|null}>
     * }
     */
    public function uploadStatus(VideoLog $videoLog): array
    {
        $this->uploadStatusCalls[] = $videoLog;

        $metadata = $videoLog->provider_metadata ?? [];
        $uploadId = $metadata['upload_id'] ?? null;

        if (! $uploadId) {
            return [
                'multipart' => false,
                'upload_id' => null,
                'part_size' => null,
                'part_count' => null,
                'parts' => [],
            ];
        }

        return [
            'multipart' => true,
            'upload_id' => (string) $uploadId,
            'part_size' => isset($metadata['part_size']) ? (int) $metadata['part_size'] : null,
            'part_count' => isset($metadata['part_count']) ? (int) $metadata['part_count'] : null,
            'parts' => $metadata['uploaded_parts'] ?? [],
        ];
    }

    public function abortUpload(VideoLog $videoLog): void
    {
        $this->abortUploadCalls[] = $videoLog;

        $metadata = $videoLog->provider_metadata ?? [];
        unset($metadata['upload_id'], $metadata['part_size'], $metadata['part_count'], $metadata['uploaded_parts']);
        $videoLog->update(['provider_metadata' => $metadata]);
    }

    public function dispatchProcessing(VideoLog $videoLog): void
    {
        $this->dispatchProcessingCalls[] = $videoLog;
    }

    public function playbackLink(VideoLog $videoLog, int $ttlSeconds = 3600): PlaybackLink
    {
        $this->playbackLinkCalls[] = [
            'videoLog' => $videoLog,
            'ttlSeconds' => $ttlSeconds,
        ];

        return new PlaybackLink(
            url: 'https://example.test/play/'.$videoLog->id,
            format: 'mp4',
            posterUrl: 'https://example.test/poster/'.$videoLog->id,
            durationSeconds: $videoLog->duration_seconds,
            expiresIn: $ttlSeconds,
        );
    }

    public function posterUrl(VideoLog $videoLog, int $ttlSeconds = 3600): ?string
    {
        $this->posterUrlCalls[] = [
            'videoLog' => $videoLog,
            'ttlSeconds' => $ttlSeconds,
        ];

        return $videoLog->poster_url;
    }

    public function deleteAssets(VideoLog $videoLog): void
    {
        $this->deleteAssetsCalls[] = $videoLog;
    }

    public function handleWebhook(WebhookRequest $request): void
    {
        $this->handleWebhookCalls[] = $request;

        $videoLogId = $request->payload['detail']['userMetadata']['video_log_id'] ?? null;
        $status = $request->payload['detail']['status'] ?? null;

        if (! $videoLogId || $status !== 'COMPLETE') {
            return;
        }

        $videoLog = VideoLog::query()->find($videoLogId);

        if ($videoLog) {
            $videoLog->update(['status' => 'ready']);
        }
    }

    public function checkStatus(VideoLog $videoLog): void
    {
        $this->checkStatusCalls[] = $videoLog;

        // Simulate polling the provider: read a canned job status off the
        // metadata so tests can drive ready / failed / still-processing.
        $status = $videoLog->provider_metadata['mediaconvert_status'] ?? null;

        if ($status === 'COMPLETE') {
            $videoLog->update(['status' => 'ready']);
        } elseif ($status === 'ERROR' || $status === 'CANCELED') {
            $videoLog->recordFailure('transcode_error', [
                'status' => $status,
                'code' => $videoLog->provider_metadata['mediaconvert_error_code'] ?? null,
                'message' => $videoLog->provider_metadata['mediaconvert_error_message'] ?? null,
            ]);
        }
    }
}
