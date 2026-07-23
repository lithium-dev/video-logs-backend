<?php

namespace Lithium\VideoLogs\Services;

use Aws\MediaConvert\MediaConvertClient;
use Aws\S3\S3Client;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Events\VideoLogReady;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Support\PlaybackLink;
use Lithium\VideoLogs\Support\UploadTarget;
use Lithium\VideoLogs\Support\WebhookRequest;

class S3VideoStorageProvider implements VideoStorageProvider
{
    public function __construct(
        private readonly S3Client $s3,
        private readonly MediaConvertClient $mediaConvert,
        private readonly array $config,   // the 'providers.s3' config block
    ) {}

    public function createUpload(VideoLog $videoLog): UploadTarget
    {
        $bucket = $this->config['bucket'];
        $sourceKey = $this->sourceKeyFor($videoLog);
        $ttl = $this->config['upload_ttl'] ?? 3600;
        $contentType = $videoLog->mime_type ?? 'video/*';

        $threshold = (int) ($this->config['multipart_threshold_bytes'] ?? (25 * 1024 * 1024));
        $sizeBytes = (int) ($videoLog->size_bytes ?? 0);
        $useMultipart = $sizeBytes >= $threshold && $sizeBytes > 0;

        if ($useMultipart) {
            return $this->createMultipartUpload($videoLog, $bucket, $sourceKey, $contentType, $ttl);
        }

        // Single-PUT path for small files.
        $command = $this->s3->getCommand('PutObject', [
            'Bucket' => $bucket,
            'Key' => $sourceKey,
            'ContentType' => $contentType,
        ]);

        $request = $this->s3->createPresignedRequest($command, "+{$ttl} seconds");

        $videoLog->provider = 's3';
        $videoLog->status = 'uploading';
        $videoLog->provider_metadata = array_merge($videoLog->provider_metadata ?? [], [
            'bucket' => $bucket,
            'source_key' => $sourceKey,
        ]);
        $videoLog->save();

        return new UploadTarget(
            method: 'PUT',
            url: (string) $request->getUri(),
            headers: ['Content-Type' => $contentType],
            expiresIn: $ttl,
        );
    }

    /**
     * Start an S3 multipart upload and return presigned URLs for every part.
     */
    private function createMultipartUpload(
        VideoLog $videoLog,
        string $bucket,
        string $sourceKey,
        string $contentType,
        int $ttl,
    ): UploadTarget {
        $partSize = $this->resolvedPartSize();
        $sizeBytes = (int) $videoLog->size_bytes;
        $partCount = (int) max(1, (int) ceil($sizeBytes / $partSize));

        $result = $this->s3->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $sourceKey,
            'ContentType' => $contentType,
        ]);

        $uploadId = (string) $result['UploadId'];

        $videoLog->provider = 's3';
        $videoLog->status = 'uploading';
        $videoLog->provider_metadata = array_merge($videoLog->provider_metadata ?? [], [
            'bucket' => $bucket,
            'source_key' => $sourceKey,
            'upload_id' => $uploadId,
            'part_size' => $partSize,
            'part_count' => $partCount,
        ]);
        $videoLog->save();

        $parts = $this->presignUploadParts($bucket, $sourceKey, $uploadId, range(1, $partCount), $ttl);

        return new UploadTarget(
            method: 'PUT',
            url: '',
            headers: ['Content-Type' => $contentType],
            parts: $parts,
            uploadId: $uploadId,
            partSize: $partSize,
            expiresIn: $ttl,
        );
    }

    public function finalizeUpload(VideoLog $videoLog, array $payload): void
    {
        $bucket = $this->bucketFor($videoLog);
        $sourceKey = $videoLog->provider_metadata['source_key'];
        $uploadId = $videoLog->provider_metadata['upload_id'] ?? null;

        if ($uploadId) {
            $this->completeMultipartUpload($videoLog, $bucket, $sourceKey, (string) $uploadId, $payload);
        }

        // Verify the object really exists before spending money transcoding it.
        $exists = $this->s3->doesObjectExist($bucket, $sourceKey);
        if (! $exists) {
            $videoLog->recordFailure('source_missing', [
                'message' => "Source object {$sourceKey} was not found in bucket {$bucket}.",
            ]);

            return;
        }

        $head = $this->s3->headObject([
            'Bucket' => $bucket,
            'Key' => $sourceKey,
        ]);

        $metadata = $videoLog->provider_metadata ?? [];
        unset($metadata['upload_id'], $metadata['part_size'], $metadata['part_count']);

        $videoLog->update([
            'status' => 'processing',
            'size_bytes' => $head['ContentLength'] ?? null,
            'mime_type' => $head['ContentType'] ?? $videoLog->mime_type,
            'provider_metadata' => $metadata,
        ]);
    }

    /**
     * @param  array{parts?: array<int, array{part_number: int, etag: string}>}  $payload
     */
    private function completeMultipartUpload(
        VideoLog $videoLog,
        string $bucket,
        string $sourceKey,
        string $uploadId,
        array $payload,
    ): void {
        $parts = $payload['parts'] ?? [];
        if ($parts === []) {
            throw new \InvalidArgumentException(
                "Multipart finalize for Video Log {$videoLog->id} requires a non-empty parts list."
            );
        }

        $s3Parts = [];
        foreach ($parts as $part) {
            $s3Parts[] = [
                'PartNumber' => (int) $part['part_number'],
                'ETag' => (string) $part['etag'],
            ];
        }

        usort($s3Parts, fn (array $a, array $b) => $a['PartNumber'] <=> $b['PartNumber']);

        $this->s3->completeMultipartUpload([
            'Bucket' => $bucket,
            'Key' => $sourceKey,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => $s3Parts,
            ],
        ]);
    }

    public function signUploadParts(VideoLog $videoLog, array $partNumbers): array
    {
        $uploadId = $videoLog->provider_metadata['upload_id'] ?? null;
        $sourceKey = $videoLog->provider_metadata['source_key'] ?? null;

        if (! $uploadId || ! $sourceKey) {
            return [];
        }

        $ttl = $this->config['upload_ttl'] ?? 3600;

        return $this->presignUploadParts(
            $this->bucketFor($videoLog),
            (string) $sourceKey,
            (string) $uploadId,
            array_map('intval', $partNumbers),
            $ttl,
        );
    }

    public function uploadStatus(VideoLog $videoLog): array
    {
        $metadata = $videoLog->provider_metadata ?? [];
        $uploadId = $metadata['upload_id'] ?? null;
        $sourceKey = $metadata['source_key'] ?? null;

        if (! $uploadId || ! $sourceKey) {
            return [
                'multipart' => false,
                'upload_id' => null,
                'part_size' => null,
                'part_count' => null,
                'parts' => [],
            ];
        }

        $bucket = $this->bucketFor($videoLog);
        $parts = [];
        $partNumberMarker = 0;

        do {
            $result = $this->s3->listParts([
                'Bucket' => $bucket,
                'Key' => $sourceKey,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $partNumberMarker,
            ]);

            foreach ($result['Parts'] ?? [] as $part) {
                $parts[] = [
                    'part_number' => (int) $part['PartNumber'],
                    'etag' => (string) $part['ETag'],
                    'size' => isset($part['Size']) ? (int) $part['Size'] : null,
                ];
            }

            $isTruncated = (bool) ($result['IsTruncated'] ?? false);
            $partNumberMarker = (int) ($result['NextPartNumberMarker'] ?? 0);
        } while ($isTruncated);

        return [
            'multipart' => true,
            'upload_id' => (string) $uploadId,
            'part_size' => isset($metadata['part_size']) ? (int) $metadata['part_size'] : null,
            'part_count' => isset($metadata['part_count']) ? (int) $metadata['part_count'] : null,
            'parts' => $parts,
        ];
    }

    public function abortUpload(VideoLog $videoLog): void
    {
        $metadata = $videoLog->provider_metadata ?? [];
        $uploadId = $metadata['upload_id'] ?? null;
        $sourceKey = $metadata['source_key'] ?? null;

        if (! $uploadId || ! $sourceKey) {
            return;
        }

        try {
            $this->s3->abortMultipartUpload([
                'Bucket' => $this->bucketFor($videoLog),
                'Key' => $sourceKey,
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable $e) {
            // Idempotent: already aborted / completed / unknown upload id.
            Log::warning('Failed to abort multipart upload for video log.', [
                'video_log_id' => $videoLog->id,
                'upload_id' => $uploadId,
                'message' => $e->getMessage(),
            ]);
        }

        unset($metadata['upload_id'], $metadata['part_size'], $metadata['part_count']);
        $videoLog->update(['provider_metadata' => $metadata]);
    }

    /**
     * @param  array<int, int>  $partNumbers
     * @return array<int, array{part_number: int, url: string}>
     */
    private function presignUploadParts(
        string $bucket,
        string $sourceKey,
        string $uploadId,
        array $partNumbers,
        int $ttl,
    ): array {
        $parts = [];

        foreach ($partNumbers as $partNumber) {
            $command = $this->s3->getCommand('UploadPart', [
                'Bucket' => $bucket,
                'Key' => $sourceKey,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
            ]);

            $request = $this->s3->createPresignedRequest($command, "+{$ttl} seconds");

            $parts[] = [
                'part_number' => $partNumber,
                'url' => (string) $request->getUri(),
            ];
        }

        return $parts;
    }

    /**
     * Resolve the multipart part size, enforcing S3's 5 MB minimum.
     */
    private function resolvedPartSize(): int
    {
        $configured = (int) ($this->config['multipart_part_size_bytes'] ?? (10 * 1024 * 1024));
        $minimum = 5 * 1024 * 1024;

        return max($minimum, $configured);
    }

    public function dispatchProcessing(VideoLog $videoLog): void
    {
        // Idempotency guard: if a job's already been submitted, don't submit another.
        if (! empty($videoLog->provider_metadata['mediaconvert_job_id'])) {
            return;
        }

        $bucket = $this->bucketFor($videoLog);
        $sourceKey = $videoLog->provider_metadata['source_key'];
        $outputPrefix = $this->outputPrefixFor($videoLog);   // where renditions + poster go

        $result = $this->mediaConvert->createJob([
            'Role' => $this->config['mediaconvert_role'],   // IAM role MediaConvert assumes
            'Settings' => [
                // User-recorded MP4/MOV files rarely carry embedded timecode.
                // Default is EMBEDDED, which can break frame-capture (poster)
                // timing; ZEROBASED starts the timeline at 00:00:00:00.
                'TimecodeConfig' => [
                    'Source' => 'ZEROBASED',
                ],
                'Inputs' => [[
                    'FileInput' => "s3://{$bucket}/{$sourceKey}",
                    // Honor the input's rotation metadata (e.g. iPhone videos
                    // recorded upright carry a 90° display matrix). Rotation is an
                    // INPUT setting on the VideoSelector — not an output
                    // VideoDescription setting — and applies to every output in
                    // the job (playback MP4 + poster). Without AUTO, MediaConvert
                    // defaults to no rotation and bakes the output un-rotated,
                    // producing sideways playback.
                    'VideoSelector' => [
                        'Rotate' => 'AUTO',
                    ],
                    // Define the audio selector the MP4 output's AudioDescription
                    // references. Without this the job fails validation with
                    // "Invalid selector_sequence_id [0] ... for audio_description".
                    'AudioSelectors' => [
                        'Audio Selector 1' => [
                            'DefaultSelection' => 'DEFAULT',
                        ],
                    ],
                ]],
                'OutputGroups' => [
                    // Group 1: the playable MP4 (720p, single bitrate for v1)
                    $this->mp4OutputGroup($bucket, $outputPrefix),
                    // Group 2: a single poster frame (thumbnail)
                    $this->posterOutputGroup($bucket, $outputPrefix),
                ],
            ],
            // Tag the job so the webhook can map it back to this videoLog.
            'UserMetadata' => ['video_log_id' => (string) $videoLog->id],
        ]);

        // MediaConvert names: {inputBaseName}{NameModifier}{ext}. Source key ends in
        // "source", so outputs are source720p.mp4 and sourceposter.0000000.jpg.
        $videoLog->provider_metadata = array_merge($videoLog->provider_metadata, [
            'mediaconvert_job_id' => $result['Job']['Id'],
            'output_prefix' => $outputPrefix,
            'playback_key' => $outputPrefix.'source720p.mp4',
            'poster_key' => $outputPrefix.'sourceposter.0000000.jpg',
        ]);
        $videoLog->save();
    }

    public function playbackLink(VideoLog $videoLog, int $ttlSeconds = 3600): PlaybackLink
    {
        if ($videoLog->status !== 'ready') {
            throw new \RuntimeException("Video Log {$videoLog->id} is not ready for playback.");
        }

        $playbackKey = $videoLog->provider_metadata['playback_key'];

        // Sign an S3 GetObject URL: only someone with this signed link can play it,
        // and only until it expires.
        $url = $this->signStorageUrl($this->bucketFor($videoLog), $playbackKey, $ttlSeconds);

        $poster = $this->posterUrl($videoLog, $ttlSeconds);

        return new PlaybackLink(
            url: $url,
            format: 'mp4',                       // becomes 'hls' if you add adaptive streaming later
            posterUrl: $poster,
            durationSeconds: $videoLog->duration_seconds,
            expiresIn: $ttlSeconds,
        );
    }

    public function posterUrl(VideoLog $videoLog, int $ttlSeconds = 3600): ?string
    {
        // Posters are public display assets, so this is a stable, non-expiring
        // URL — the $ttlSeconds argument (part of the interface for signed
        // assets) is intentionally ignored here. Prefer the key promoted to a
        // column at completion, falling back to the key recorded in
        // provider_metadata when the job was dispatched.
        $posterKey = $videoLog->poster_key
            ?? $videoLog->provider_metadata['poster_key']
            ?? null;

        if (! $posterKey) {
            return null;
        }

        return $this->publicUrl($this->bucketFor($videoLog), $posterKey);
    }

    public function deleteAssets(VideoLog $videoLog): void
    {
        $metadata = $videoLog->provider_metadata ?? [];
        $bucket = $this->bucketFor($videoLog);

        // Collect everything: source + all outputs under the prefix.
        $keys = array_filter([
            $metadata['source_key'] ?? null,
        ]);

        // Delete the whole output prefix (renditions + poster). Paginate: a
        // single ListObjectsV2 returns at most 1000 keys.
        if (! empty($metadata['output_prefix'])) {
            $continuationToken = null;
            do {
                $params = [
                    'Bucket' => $bucket,
                    'Prefix' => $metadata['output_prefix'],
                ];
                if ($continuationToken !== null) {
                    $params['ContinuationToken'] = $continuationToken;
                }

                $objects = $this->s3->listObjectsV2($params);
                foreach ($objects['Contents'] ?? [] as $object) {
                    $keys[] = $object['Key'];
                }

                $continuationToken = ($objects['IsTruncated'] ?? false)
                    ? ($objects['NextContinuationToken'] ?? null)
                    : null;
            } while ($continuationToken !== null);
        }

        if (empty($keys)) {
            return;   // nothing to delete — idempotent no-op
        }

        // DeleteObjects accepts at most 1000 keys per call.
        foreach (array_chunk(array_values(array_unique($keys)), 1000) as $chunk) {
            $this->s3->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => ['Objects' => array_map(fn ($k) => ['Key' => $k], $chunk)],
            ]);
        }
    }

    public function handleWebhook(WebhookRequest $request): void
    {
        // 1. Verify authenticity — reject forged calls. A forged "ready" must not
        //    flip a video live. Can be disabled for local testing via config.
        $verifySignature = $this->config['verify_webhook_signature'] ?? true;
        if ($verifySignature && ! $this->verifyWebhookSignature($request)) {
            throw new \RuntimeException('Invalid webhook signature.');
        }

        // 2. The endpoint receives an SNS envelope (EventBridge -> SNS -> HTTPS).
        //    Decode it so we can branch on the SNS message type.
        $envelope = json_decode($request->rawBody, true);
        if (! is_array($envelope)) {
            return;
        }

        $type = $this->snsMessageType($request, $envelope);

        // 3. SNS requires the endpoint to confirm the subscription by visiting
        //    the SubscribeURL — otherwise no notifications are ever delivered.
        if ($type === 'SubscriptionConfirmation') {
            $this->confirmSnsSubscription($envelope);

            return;
        }

        if ($type === 'UnsubscribeConfirmation') {
            return;   // nothing to do
        }

        // 4. For a Notification the EventBridge event is JSON-encoded inside the
        //    "Message" field. If there's no envelope (e.g. a raw EventBridge API
        //    destination), treat the body itself as the event.
        $event = array_key_exists('Message', $envelope)
            ? json_decode((string) $envelope['Message'], true)
            : $envelope;

        if (is_array($event)) {
            $this->applyEventBridgeEvent($event);
        }
    }

    /**
     * Apply a MediaConvert "Job State Change" EventBridge event to its VideoLog.
     *
     * @param  array<string, mixed>  $event
     */
    private function applyEventBridgeEvent(array $event): void
    {
        $detail = $event['detail'] ?? [];

        // Resolve which VideoLog this is about, via the id we tagged onto the job.
        $videoLogId = $detail['userMetadata']['video_log_id'] ?? null;
        $videoLog = VideoLog::find($videoLogId);
        if (! $videoLog) {
            return;   // unknown / already deleted — nothing to do
        }

        $this->applyJobStatus($videoLog, $detail['status'] ?? null, [
            'code' => $detail['errorCode'] ?? null,
            'message' => $detail['errorMessage'] ?? null,
        ]);
    }

    /**
     * Resolve the SNS message type from the header (preferred) or the body.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function snsMessageType(WebhookRequest $request, array $envelope): ?string
    {
        $header = $request->headers['x-amz-sns-message-type'] ?? null;
        if (is_array($header)) {
            $header = $header[0] ?? null;
        }

        return $header ?? ($envelope['Type'] ?? null);
    }

    /**
     * Confirm an SNS subscription by visiting the one-time SubscribeURL.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function confirmSnsSubscription(array $envelope): void
    {
        $subscribeUrl = $envelope['SubscribeURL'] ?? null;
        if (! is_string($subscribeUrl) || $subscribeUrl === '') {
            return;
        }

        $response = Http::get($subscribeUrl);
        if ($response->failed()) {
            Log::warning('Failed to confirm SNS subscription for video-logs webhook.', [
                'subscribe_url' => $subscribeUrl,
                'status' => $response->status(),
            ]);
        }
    }

    public function checkStatus(VideoLog $videoLog): void
    {
        // No job was ever submitted — nothing to reconcile against.
        $jobId = $videoLog->provider_metadata['mediaconvert_job_id'] ?? null;
        if (empty($jobId)) {
            return;
        }

        // Ask MediaConvert directly what state the job is in. This is the
        // pull-based equivalent of the webhook, for when the webhook never
        // arrives (not configured) or failed to be delivered.
        try {
            $result = $this->mediaConvert->getJob(['Id' => $jobId]);
        } catch (\Throwable $e) {
            // Throttling, an expired/unknown job id, etc. Don't 500 the caller
            // or flip status on a transient error — log and leave as-is.
            Log::warning('Failed to fetch MediaConvert job status.', [
                'video_log_id' => $videoLog->id,
                'mediaconvert_job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $job = $result['Job'] ?? [];
        $status = $job['Status'] ?? null;   // SUBMITTED | PROGRESSING | COMPLETE | CANCELED | ERROR

        $this->applyJobStatus($videoLog, $status, [
            'code' => $job['ErrorCode'] ?? null,
            'message' => $job['ErrorMessage'] ?? null,
        ]);
    }

    /**
     * Reconcile a VideoLog with a MediaConvert job status, firing the matching
     * domain event. Terminal states only; transient states (SUBMITTED /
     * PROGRESSING) leave the log untouched so it stays "processing".
     *
     * @param  array{code?: int|string|null, message?: string|null}  $error
     */
    private function applyJobStatus(VideoLog $videoLog, ?string $status, array $error = []): void
    {
        if ($status === 'COMPLETE') {
            $this->markReady($videoLog);
            event(new VideoLogReady($videoLog));
        } elseif ($status === 'ERROR' || $status === 'CANCELED') {
            $videoLog->recordFailure('transcode_error', [
                'status' => $status,
                'code' => $error['code'] ?? null,
                'message' => $error['message'] ?? null,
            ]);
        }
    }

    /**
     * Promote the transcode outputs recorded at dispatch time onto the video log
     * and store a signed poster URL. The poster is generated by MediaConvert's
     * FRAME_CAPTURE output (see posterOutputGroup); this is where that generated
     * asset gets "stored" on the record so the index/grid can render a thumbnail
     * without enriching each row at read time.
     */
    private function markReady(VideoLog $videoLog): void
    {
        $metadata = $videoLog->provider_metadata ?? [];
        $bucket = $this->bucketFor($videoLog);

        $playbackKey = $metadata['playback_key'] ?? null;
        $posterKey = $metadata['poster_key'] ?? null;

        $videoLog->update([
            'status' => 'ready',
            'playback_key' => $playbackKey,
            'poster_key' => $posterKey,
            'playback_format' => 'mp4',
            // Public, non-expiring URL — the poster is written public-read by
            // MediaConvert (see posterOutputGroup), so unlike the playback MP4 it
            // never needs presigning.
            'poster_url' => $posterKey
                ? $this->publicUrl($bucket, $posterKey)
                : null,
        ]);
    }

    private function sourceKeyFor(VideoLog $videoLog): string
    {
        return $this->outputPrefixFor($videoLog).'source';
    }

    private function outputPrefixFor(VideoLog $videoLog): string
    {
        $base = trim($this->config['output_prefix'] ?? 'video-logs/', '/');
        $type = $videoLog->loggable_type
            ? Str::slug(str_replace('\\', '-', $videoLog->loggable_type))
            : 'unattached';
        $id = $videoLog->loggable_id ?? '0';

        return "{$base}/{$type}/{$id}/{$videoLog->id}/";
    }

    private function mp4OutputGroup(string $bucket, string $outputPrefix): array
    {
        return [
            'Name' => 'File Group',
            'OutputGroupSettings' => [
                'Type' => 'FILE_GROUP_SETTINGS',
                'FileGroupSettings' => [
                    'Destination' => "s3://{$bucket}/{$outputPrefix}",
                ],
            ],
            'Outputs' => [
                [
                    'NameModifier' => '720p',
                    'ContainerSettings' => [
                        'Container' => 'MP4',
                    ],
                    'VideoDescription' => [
                        // Specify only the height and let MediaConvert compute the
                        // width to preserve the source aspect ratio (after the
                        // input-level rotation). Hardcoding both dimensions would
                        // squish any non-16:9 source, e.g. portrait phone
                        // recordings.
                        'Height' => 720,
                        'CodecSettings' => [
                            'Codec' => 'H_264',
                            'H264Settings' => [
                                'RateControlMode' => 'QVBR',
                                'MaxBitrate' => 5000000,
                            ],
                        ],
                    ],
                    'AudioDescriptions' => [
                        [
                            // Must match a selector name defined on the input above.
                            'AudioSourceName' => 'Audio Selector 1',
                            'CodecSettings' => [
                                'Codec' => 'AAC',
                                'AacSettings' => [
                                    'Bitrate' => 96000,
                                    'CodingMode' => 'CODING_MODE_2_0',
                                    'SampleRate' => 48000,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function posterOutputGroup(string $bucket, string $outputPrefix): array
    {
        return [
            'Name' => 'Poster Frame',
            'OutputGroupSettings' => [
                'Type' => 'FILE_GROUP_SETTINGS',
                'FileGroupSettings' => [
                    'Destination' => "s3://{$bucket}/{$outputPrefix}",
                    // The poster is a public display asset (like an uploaded
                    // image, which Laravel's 'public' disk stores public-read).
                    // Write it public-read so it's served over a stable URL with
                    // no presigning. Requires the MediaConvert role to hold
                    // s3:PutObjectAcl in addition to s3:PutObject — without it the
                    // write fails with Access Denied (see docs/AWS-SETUP.md).
                    'DestinationSettings' => [
                        'S3Settings' => [
                            'AccessControl' => [
                                'CannedAcl' => 'PUBLIC_READ',
                            ],
                        ],
                    ],
                ],
            ],
            'Outputs' => [
                [
                    'NameModifier' => 'poster',
                    'ContainerSettings' => [
                        'Container' => 'RAW',
                    ],
                    'VideoDescription' => [
                        // Orientation is corrected at the input (VideoSelector
                        // Rotate = AUTO), which applies to this poster output too,
                        // so the thumbnail matches the playback orientation.
                        'CodecSettings' => [
                            'Codec' => 'FRAME_CAPTURE',
                            'FrameCaptureSettings' => [
                                'FramerateNumerator' => 1,
                                'FramerateDenominator' => 1,
                                'MaxCaptures' => 1,
                                'Quality' => 80,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a stable, publicly-accessible URL for an object using the bucket's
     * virtual-hosted S3 URL. Used for public assets like posters — no signing,
     * no expiry.
     */
    private function publicUrl(string $bucket, string $key): string
    {
        $region = $this->config['region'] ?? 'us-east-1';

        return "https://{$bucket}.s3.{$region}.amazonaws.com/".ltrim($key, '/');
    }

    private function signStorageUrl(string $bucket, string $key, int $ttlSeconds): string
    {
        $command = $this->s3->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        return (string) $this->s3
            ->createPresignedRequest($command, "+{$ttlSeconds} seconds")
            ->getUri();
    }

    /**
     * The bucket a given video log actually lives in. Prefers the value stored
     * on the record at upload time, falling back to current config for records
     * created before per-record bucket tracking existed.
     */
    private function bucketFor(VideoLog $videoLog): string
    {
        return $videoLog->provider_metadata['bucket'] ?? $this->config['bucket'];
    }

    private function verifyWebhookSignature(WebhookRequest $request): bool
    {
        try {
            $message = Message::fromJsonString($request->rawBody);

            $isValid = (new MessageValidator)->isValid($message);

            if (! $isValid) {
                Log::warning('Video-logs webhook signature did not validate.', [
                    'sns_message_type' => $message['Type'] ?? null,
                    'signature_version' => $message['SignatureVersion'] ?? null,
                ]);
            }

            return $isValid;
        } catch (\Throwable $e) {
            // Surface the real reason (cert fetch failure, malformed body,
            // parse error, etc.) instead of silently reporting "invalid".
            Log::warning('Video-logs webhook signature verification threw.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'raw_body_prefix' => Str::limit($request->rawBody, 500),
            ]);

            return false;
        }
    }
}
