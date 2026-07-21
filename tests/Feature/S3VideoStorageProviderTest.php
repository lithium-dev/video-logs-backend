<?php

use Aws\CommandInterface;
use Aws\MediaConvert\MediaConvertClient;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lithium\VideoLogs\Events\VideoLogFailed;
use Lithium\VideoLogs\Events\VideoLogReady;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Services\S3VideoStorageProvider;
use Lithium\VideoLogs\Support\WebhookRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

function makeS3Provider(MediaConvertClient $mediaConvert): S3VideoStorageProvider
{
    return new S3VideoStorageProvider(
        s3: Mockery::mock(S3Client::class),
        mediaConvert: $mediaConvert,
        config: ['bucket' => 'test-bucket'],
    );
}

function makeWebhookProvider(): S3VideoStorageProvider
{
    return new S3VideoStorageProvider(
        s3: Mockery::mock(S3Client::class),
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: ['bucket' => 'test-bucket', 'verify_webhook_signature' => false],
    );
}

function snsWebhookRequest(array $envelope): WebhookRequest
{
    $type = $envelope['Type'] ?? 'Notification';

    return new WebhookRequest(
        payload: $envelope,
        headers: ['x-amz-sns-message-type' => [$type]],
        rawBody: json_encode($envelope),
    );
}

function makeProcessingVideoLog(): VideoLog
{
    return VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'processing',
        'provider' => 's3',
        'provider_metadata' => ['mediaconvert_job_id' => 'job-123'],
    ]);
}

it('stores and logs the AWS failure reason when checking status', function () {
    Event::fake([VideoLogFailed::class]);
    Log::spy();

    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('getJob')
        ->once()
        ->with(['Id' => 'job-123'])
        ->andReturn([
            'Job' => [
                'Status' => 'ERROR',
                'ErrorCode' => 1404,
                'ErrorMessage' => 'Unable to open input file [s3://bucket/source.mov]',
            ],
        ]);

    $videoLog = makeProcessingVideoLog();

    makeS3Provider($mediaConvert)->checkStatus($videoLog);

    $fresh = $videoLog->fresh();
    $failure = $fresh->provider_metadata['failure'];

    expect($fresh->status)->toBe('failed')
        ->and($failure['reason'])->toBe('transcode_error')
        ->and($failure['status'])->toBe('ERROR')
        ->and($failure['code'])->toBe(1404)
        ->and($failure['message'])->toBe('Unable to open input file [s3://bucket/source.mov]')
        ->and($failure)->toHaveKey('failed_at');

    Event::assertDispatched(VideoLogFailed::class, function (VideoLogFailed $event) use ($fresh) {
        return $event->videoLog->id === $fresh->id && $event->reason === 'transcode_error';
    });

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'Video log processing failed.'
                && $context['error_code'] === 1404
                && $context['error_message'] === 'Unable to open input file [s3://bucket/source.mov]';
        });
});

it('marks a video log ready when MediaConvert reports COMPLETE', function () {
    Event::fake([VideoLogReady::class]);

    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('getJob')
        ->once()
        ->andReturn(['Job' => ['Status' => 'COMPLETE']]);

    $videoLog = makeProcessingVideoLog();

    makeS3Provider($mediaConvert)->checkStatus($videoLog);

    expect($videoLog->fresh()->status)->toBe('ready');

    Event::assertDispatched(VideoLogReady::class);
});

it('stores the poster and playback keys and a public poster url on COMPLETE', function () {
    Event::fake([VideoLogReady::class]);

    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('getJob')
        ->once()
        ->andReturn(['Job' => ['Status' => 'COMPLETE']]);

    // The poster is public: no presigning should occur when marking ready.
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldNotReceive('getCommand');
    $s3->shouldNotReceive('createPresignedRequest');

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: $mediaConvert,
        config: ['bucket' => 'test-bucket', 'region' => 'us-west-2'],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'processing',
        'provider' => 's3',
        'provider_metadata' => [
            'mediaconvert_job_id' => 'job-123',
            'playback_key' => 'video-logs/customer/1/1/source720p.mp4',
            'poster_key' => 'video-logs/customer/1/1/sourceposter.0000000.jpg',
        ],
    ]);

    $provider->checkStatus($videoLog);

    $fresh = $videoLog->fresh();

    expect($fresh->status)->toBe('ready')
        ->and($fresh->poster_key)->toBe('video-logs/customer/1/1/sourceposter.0000000.jpg')
        ->and($fresh->playback_key)->toBe('video-logs/customer/1/1/source720p.mp4')
        ->and($fresh->poster_url)->toBe('https://test-bucket.s3.us-west-2.amazonaws.com/video-logs/customer/1/1/sourceposter.0000000.jpg');

    Event::assertDispatched(VideoLogReady::class);
});

it('leaves the video log processing while MediaConvert is still working', function () {
    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('getJob')
        ->once()
        ->andReturn(['Job' => ['Status' => 'PROGRESSING']]);

    $videoLog = makeProcessingVideoLog();

    makeS3Provider($mediaConvert)->checkStatus($videoLog);

    $fresh = $videoLog->fresh();

    expect($fresh->status)->toBe('processing')
        ->and($fresh->provider_metadata)->not->toHaveKey('failure');
});

it('submits a job whose audio description references a defined audio selector', function () {
    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('createJob')
        ->once()
        ->with(Mockery::on(function (array $args) {
            $input = $args['Settings']['Inputs'][0];
            $mp4Output = $args['Settings']['OutputGroups'][0]['Outputs'][0];

            return isset($input['AudioSelectors']['Audio Selector 1'])
                && ($mp4Output['AudioDescriptions'][0]['AudioSourceName'] ?? null) === 'Audio Selector 1'
                && ($args['Settings']['TimecodeConfig']['Source'] ?? null) === 'ZEROBASED';
        }))
        ->andReturn(['Job' => ['Id' => 'job-xyz']]);

    $provider = new S3VideoStorageProvider(
        s3: Mockery::mock(S3Client::class),
        mediaConvert: $mediaConvert,
        config: [
            'bucket' => 'test-bucket',
            'mediaconvert_role' => 'arn:aws:iam::123:role/mc',
            'output_prefix' => 'video-logs/',
        ],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'processing',
        'provider' => 's3',
        'provider_metadata' => ['source_key' => 'video-logs/customer/1/1/source'],
    ]);

    $provider->dispatchProcessing($videoLog);

    expect($videoLog->fresh()->provider_metadata['mediaconvert_job_id'])->toBe('job-xyz');
});

it('writes the poster output group as public-read', function () {
    // Mirrors how uploaded images are stored public (Laravel 'public' disk =>
    // public-read ACL). Requires s3:PutObjectAcl on the MediaConvert role.
    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('createJob')
        ->once()
        ->with(Mockery::on(function (array $args) {
            $posterGroup = collect($args['Settings']['OutputGroups'])
                ->firstWhere('Name', 'Poster Frame');

            $cannedAcl = $posterGroup['OutputGroupSettings']['FileGroupSettings']['DestinationSettings']['S3Settings']['AccessControl']['CannedAcl'] ?? null;

            return $cannedAcl === 'PUBLIC_READ';
        }))
        ->andReturn(['Job' => ['Id' => 'job-public']]);

    $provider = new S3VideoStorageProvider(
        s3: Mockery::mock(S3Client::class),
        mediaConvert: $mediaConvert,
        config: [
            'bucket' => 'test-bucket',
            'mediaconvert_role' => 'arn:aws:iam::123:role/mc',
            'output_prefix' => 'video-logs/',
        ],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'processing',
        'provider' => 's3',
        'provider_metadata' => ['source_key' => 'video-logs/customer/1/1/source'],
    ]);

    $provider->dispatchProcessing($videoLog);
});

it('does not call MediaConvert when there is no job id', function () {
    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldNotReceive('getJob');

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'processing',
        'provider' => 's3',
        'provider_metadata' => [],
    ]);

    makeS3Provider($mediaConvert)->checkStatus($videoLog);

    expect($videoLog->fresh()->status)->toBe('processing');
});

it('leaves status unchanged when MediaConvert getJob throws', function () {
    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('getJob')->once()->andThrow(new RuntimeException('throttled'));

    $videoLog = makeProcessingVideoLog();

    makeS3Provider($mediaConvert)->checkStatus($videoLog);

    expect($videoLog->fresh()->status)->toBe('processing');
});

it('marks a video log ready from an SNS Notification webhook', function () {
    $videoLog = makeProcessingVideoLog();

    $event = [
        'detail' => [
            'status' => 'COMPLETE',
            'userMetadata' => ['video_log_id' => (string) $videoLog->id],
        ],
    ];

    makeWebhookProvider()->handleWebhook(snsWebhookRequest([
        'Type' => 'Notification',
        'Message' => json_encode($event),
    ]));

    expect($videoLog->fresh()->status)->toBe('ready');
});

it('marks a video log failed with reason from an SNS Notification error webhook', function () {
    $videoLog = makeProcessingVideoLog();

    $event = [
        'detail' => [
            'status' => 'ERROR',
            'errorCode' => 1401,
            'errorMessage' => 'Transcode blew up',
            'userMetadata' => ['video_log_id' => (string) $videoLog->id],
        ],
    ];

    makeWebhookProvider()->handleWebhook(snsWebhookRequest([
        'Type' => 'Notification',
        'Message' => json_encode($event),
    ]));

    $fresh = $videoLog->fresh();

    expect($fresh->status)->toBe('failed')
        ->and($fresh->provider_metadata['failure']['message'])->toBe('Transcode blew up')
        ->and($fresh->provider_metadata['failure']['code'])->toBe(1401);
});

it('confirms an SNS subscription by visiting the SubscribeURL', function () {
    Http::fake();

    $subscribeUrl = 'https://sns.us-west-2.amazonaws.com/?Action=ConfirmSubscription&Token=abc123';

    makeWebhookProvider()->handleWebhook(snsWebhookRequest([
        'Type' => 'SubscriptionConfirmation',
        'SubscribeURL' => $subscribeUrl,
    ]));

    Http::assertSent(fn ($request) => $request->url() === $subscribeUrl);
});

it('submits the job to the bucket persisted on the record, not the config bucket', function () {
    $mediaConvert = Mockery::mock(MediaConvertClient::class);
    $mediaConvert->shouldReceive('createJob')
        ->once()
        ->with(Mockery::on(function (array $args) {
            return str_starts_with($args['Settings']['Inputs'][0]['FileInput'], 's3://recorded-bucket/');
        }))
        ->andReturn(['Job' => ['Id' => 'job-1']]);

    $provider = new S3VideoStorageProvider(
        s3: Mockery::mock(S3Client::class),
        mediaConvert: $mediaConvert,
        config: [
            'bucket' => 'config-bucket',
            'mediaconvert_role' => 'arn:aws:iam::123:role/mc',
            'output_prefix' => 'video-logs/',
        ],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'processing',
        'provider' => 's3',
        'provider_metadata' => [
            'bucket' => 'recorded-bucket',
            'source_key' => 'video-logs/customer/1/1/source',
        ],
    ]);

    $provider->dispatchProcessing($videoLog);
});

it('persists the bucket on the record at upload time', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('getCommand')->once()->andReturn(Mockery::mock(CommandInterface::class));

    $uri = Mockery::mock(UriInterface::class);
    $uri->shouldReceive('__toString')->andReturn('https://config-bucket.s3.amazonaws.com/key');
    $psrRequest = Mockery::mock(RequestInterface::class);
    $psrRequest->shouldReceive('getUri')->andReturn($uri);
    $s3->shouldReceive('createPresignedRequest')->once()->andReturn($psrRequest);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: ['bucket' => 'config-bucket', 'upload_ttl' => 3600],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Clip',
        'status' => 'pending',
        'provider' => 's3',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024,
    ]);

    $provider->createUpload($videoLog);

    expect($videoLog->fresh()->provider_metadata['bucket'])->toBe('config-bucket');
});

it('uses single-PUT createUpload below the multipart threshold', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('getCommand')
        ->once()
        ->with('PutObject', Mockery::type('array'))
        ->andReturn(Mockery::mock(CommandInterface::class));
    $s3->shouldNotReceive('createMultipartUpload');

    $uri = Mockery::mock(UriInterface::class);
    $uri->shouldReceive('__toString')->andReturn('https://test-bucket.s3.amazonaws.com/key');
    $psrRequest = Mockery::mock(RequestInterface::class);
    $psrRequest->shouldReceive('getUri')->andReturn($uri);
    $s3->shouldReceive('createPresignedRequest')->once()->andReturn($psrRequest);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: [
            'bucket' => 'test-bucket',
            'upload_ttl' => 3600,
            'multipart_threshold_bytes' => 25 * 1024 * 1024,
            'multipart_part_size_bytes' => 10 * 1024 * 1024,
        ],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Small clip',
        'status' => 'pending',
        'provider' => 's3',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024 * 1024, // 1 MB — below threshold
    ]);

    $target = $provider->createUpload($videoLog);

    expect($target->uploadId)->toBeNull()
        ->and($target->parts)->toBeNull()
        ->and($target->url)->toContain('https://')
        ->and($videoLog->fresh()->provider_metadata)->not->toHaveKey('upload_id');
});

it('creates a multipart upload above the threshold', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('createMultipartUpload')
        ->once()
        ->andReturn(['UploadId' => 'mpu-123']);

    // 30 MB / 10 MB = 3 parts
    $s3->shouldReceive('getCommand')
        ->times(3)
        ->with('UploadPart', Mockery::on(function (array $args) {
            return ($args['UploadId'] ?? null) === 'mpu-123'
                && isset($args['PartNumber']);
        }))
        ->andReturn(Mockery::mock(CommandInterface::class));

    $uri = Mockery::mock(UriInterface::class);
    $uri->shouldReceive('__toString')->andReturn('https://test-bucket.s3.amazonaws.com/part');
    $psrRequest = Mockery::mock(RequestInterface::class);
    $psrRequest->shouldReceive('getUri')->andReturn($uri);
    $s3->shouldReceive('createPresignedRequest')->times(3)->andReturn($psrRequest);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: [
            'bucket' => 'test-bucket',
            'upload_ttl' => 3600,
            'multipart_threshold_bytes' => 25 * 1024 * 1024,
            'multipart_part_size_bytes' => 10 * 1024 * 1024,
            'output_prefix' => 'video-logs/',
        ],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Large clip',
        'status' => 'pending',
        'provider' => 's3',
        'mime_type' => 'video/mp4',
        'size_bytes' => 30 * 1024 * 1024,
    ]);

    $target = $provider->createUpload($videoLog);
    $fresh = $videoLog->fresh();

    expect($target->uploadId)->toBe('mpu-123')
        ->and($target->partSize)->toBe(10 * 1024 * 1024)
        ->and($target->parts)->toHaveCount(3)
        ->and($target->parts[0]['part_number'])->toBe(1)
        ->and($fresh->provider_metadata['upload_id'])->toBe('mpu-123')
        ->and($fresh->provider_metadata['part_count'])->toBe(3)
        ->and($fresh->provider_metadata['part_size'])->toBe(10 * 1024 * 1024);
});

it('completes a multipart upload on finalize', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('completeMultipartUpload')
        ->once()
        ->with(Mockery::on(function (array $args) {
            $parts = $args['MultipartUpload']['Parts'] ?? [];

            return ($args['UploadId'] ?? null) === 'mpu-123'
                && count($parts) === 2
                && $parts[0]['PartNumber'] === 1
                && $parts[1]['PartNumber'] === 2;
        }))
        ->andReturn([]);
    $s3->shouldReceive('doesObjectExist')->once()->andReturn(true);
    $s3->shouldReceive('headObject')->once()->andReturn([
        'ContentLength' => 20_000_000,
        'ContentType' => 'video/mp4',
    ]);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: ['bucket' => 'test-bucket'],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Large clip',
        'status' => 'pending',
        'provider' => 's3',
        'mime_type' => 'video/mp4',
        'provider_metadata' => [
            'bucket' => 'test-bucket',
            'source_key' => 'video-logs/customer/1/1/source',
            'upload_id' => 'mpu-123',
            'part_size' => 10_485_760,
            'part_count' => 2,
        ],
    ]);

    $provider->finalizeUpload($videoLog, [
        'parts' => [
            ['part_number' => 2, 'etag' => '"etag-2"'],
            ['part_number' => 1, 'etag' => '"etag-1"'],
        ],
    ]);

    $fresh = $videoLog->fresh();

    expect($fresh->status)->toBe('processing')
        ->and($fresh->size_bytes)->toBe(20_000_000)
        ->and($fresh->provider_metadata)->not->toHaveKey('upload_id')
        ->and($fresh->provider_metadata)->not->toHaveKey('part_count');
});

it('lists uploaded parts for uploadStatus', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('listParts')
        ->once()
        ->andReturn([
            'Parts' => [
                ['PartNumber' => 1, 'ETag' => '"etag-1"', 'Size' => 10_485_760],
                ['PartNumber' => 2, 'ETag' => '"etag-2"', 'Size' => 5_000_000],
            ],
            'IsTruncated' => false,
        ]);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: ['bucket' => 'test-bucket'],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Large clip',
        'status' => 'pending',
        'provider' => 's3',
        'provider_metadata' => [
            'bucket' => 'test-bucket',
            'source_key' => 'video-logs/customer/1/1/source',
            'upload_id' => 'mpu-123',
            'part_size' => 10_485_760,
            'part_count' => 2,
        ],
    ]);

    $status = $provider->uploadStatus($videoLog);

    expect($status['multipart'])->toBeTrue()
        ->and($status['upload_id'])->toBe('mpu-123')
        ->and($status['parts'])->toHaveCount(2)
        ->and($status['parts'][0]['part_number'])->toBe(1)
        ->and($status['parts'][0]['etag'])->toBe('"etag-1"');
});

it('aborts a multipart upload', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('abortMultipartUpload')
        ->once()
        ->with(Mockery::on(function (array $args) {
            return ($args['UploadId'] ?? null) === 'mpu-123';
        }))
        ->andReturn([]);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: ['bucket' => 'test-bucket'],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Large clip',
        'status' => 'pending',
        'provider' => 's3',
        'provider_metadata' => [
            'bucket' => 'test-bucket',
            'source_key' => 'video-logs/customer/1/1/source',
            'upload_id' => 'mpu-123',
            'part_size' => 10_485_760,
            'part_count' => 2,
        ],
    ]);

    $provider->abortUpload($videoLog);

    expect($videoLog->fresh()->provider_metadata)->not->toHaveKey('upload_id');
});

it('signs individual upload parts for resume', function () {
    $s3 = Mockery::mock(S3Client::class);
    $s3->shouldReceive('getCommand')
        ->twice()
        ->with('UploadPart', Mockery::type('array'))
        ->andReturn(Mockery::mock(CommandInterface::class));

    $uri = Mockery::mock(UriInterface::class);
    $uri->shouldReceive('__toString')->andReturn('https://test-bucket.s3.amazonaws.com/part');
    $psrRequest = Mockery::mock(RequestInterface::class);
    $psrRequest->shouldReceive('getUri')->andReturn($uri);
    $s3->shouldReceive('createPresignedRequest')->twice()->andReturn($psrRequest);

    $provider = new S3VideoStorageProvider(
        s3: $s3,
        mediaConvert: Mockery::mock(MediaConvertClient::class),
        config: ['bucket' => 'test-bucket', 'upload_ttl' => 3600],
    );

    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Large clip',
        'status' => 'pending',
        'provider' => 's3',
        'provider_metadata' => [
            'bucket' => 'test-bucket',
            'source_key' => 'video-logs/customer/1/1/source',
            'upload_id' => 'mpu-123',
        ],
    ]);

    $parts = $provider->signUploadParts($videoLog, [2, 3]);

    expect($parts)->toHaveCount(2)
        ->and($parts[0]['part_number'])->toBe(2)
        ->and($parts[1]['part_number'])->toBe(3);
});
