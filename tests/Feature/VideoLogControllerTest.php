<?php

use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Tests\Fixtures\FakeVideoStorageProvider;
use Lithium\VideoLogs\Tests\Fixtures\User;

function bindFakeProvider(): FakeVideoStorageProvider
{
    $fake = new FakeVideoStorageProvider;
    app()->instance(VideoStorageProvider::class, $fake);

    return $fake;
}

function actingAsUser(string $name = 'Test User'): User
{
    $user = User::query()->create(['name' => $name]);

    test()->actingAs($user);

    return $user;
}

function createVideoLog(array $attributes = []): VideoLog
{
    return VideoLog::query()->create(array_merge([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Site walkthrough',
        'description' => 'A sample clip',
        'status' => 'pending',
        'provider' => 'fake',
        'duration_seconds' => 30,
        'mime_type' => 'video/mp4',
        'provider_metadata' => ['source_key' => 'fake/source/1'],
    ], $attributes));
}

it('stores a video log and returns an upload target from the provider', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $response = $this->postJson('video-logs', [
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Site walkthrough',
        'description' => 'A sample clip',
        'duration_seconds' => 30,
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
    ]);

    $response->assertOk()
        ->assertJsonPath('upload_target.url', 'https://example.test/upload/1')
        ->assertJsonPath('upload_target.method', 'PUT')
        ->assertJsonPath('video_log.title', 'Site walkthrough')
        ->assertJsonPath('video_log.mime_type', 'video/mp4');

    expect($fake->createUploadCalls)->toHaveCount(1)
        ->and(VideoLog::query()->count())->toBe(1);
});

it('indexes video logs filtered by loggable', function () {
    bindFakeProvider();
    actingAsUser();

    createVideoLog(['title' => 'First']);
    createVideoLog(['title' => 'Second', 'loggable_id' => 2]);

    $response = $this->getJson('video-logs?loggable_type=customer&loggable_id=1');

    $response->assertOk();

    $titles = collect($response->json())->pluck('title');

    expect($titles)->toContain('First')
        ->and($titles)->not->toContain('Second');
});

it('resolves the uploader name when storing a video log', function () {
    bindFakeProvider();
    actingAsUser('Jane Doe');

    $response = $this->postJson('video-logs', [
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Site walkthrough',
        'description' => 'A sample clip',
        'duration_seconds' => 30,
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
    ]);

    $response->assertOk()
        ->assertJsonPath('video_log.user_name', 'Jane Doe');
});

it('resolves the uploader name when indexing video logs', function () {
    bindFakeProvider();
    actingAsUser('Jane Doe');

    createVideoLog(['title' => 'First']);

    $response = $this->getJson('video-logs?loggable_type=customer&loggable_id=1');

    $response->assertOk()
        ->assertJsonPath('0.user_name', 'Jane Doe');
});

it('resolves the uploader name when showing a video log', function () {
    bindFakeProvider();
    actingAsUser('Jane Doe');

    $videoLog = createVideoLog(['status' => 'ready']);

    $response = $this->getJson("video-logs/{$videoLog->id}");

    $response->assertOk()
        ->assertJsonPath('user_name', 'Jane Doe');
});

it('shows a ready video log with playback from the provider', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog(['status' => 'ready']);

    $response = $this->getJson("video-logs/{$videoLog->id}");

    $response->assertOk()
        ->assertJsonPath('playback.url', 'https://example.test/play/'.$videoLog->id)
        ->assertJsonPath('playback.format', 'mp4');

    expect($fake->playbackLinkCalls)->toHaveCount(1);
});

it('finalizes upload through the provider and dispatches processing', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog(['status' => 'pending']);

    $response = $this->postJson("video-logs/{$videoLog->id}/finalize_upload");

    $response->assertOk()
        ->assertJsonPath('status', 'processing');

    expect($fake->finalizeUploadCalls)->toHaveCount(1)
        ->and($fake->dispatchProcessingCalls)->toHaveCount(1)
        ->and($videoLog->fresh()->status)->toBe('processing');
});

it('retries processing for a failed video log', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'failed',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'mediaconvert_job_id' => 'job-123',
            'output_prefix' => 'fake/output/1/',
            'playback_key' => 'fake/output/1/source720p.mp4',
            'poster_key' => 'fake/output/1/sourceposter.0000000.jpg',
        ],
    ]);

    $response = $this->postJson("video-logs/{$videoLog->id}/retry_processing");

    $response->assertOk()
        ->assertJsonPath('status', 'processing');

    $fresh = $videoLog->fresh();

    expect($fake->dispatchProcessingCalls)->toHaveCount(1)
        ->and($fresh->status)->toBe('processing')
        ->and($fresh->provider_metadata)->not->toHaveKey('mediaconvert_job_id')
        ->and($fresh->provider_metadata)->not->toHaveKey('output_prefix')
        ->and($fresh->provider_metadata)->not->toHaveKey('playback_key')
        ->and($fresh->provider_metadata)->not->toHaveKey('poster_key')
        ->and($fresh->provider_metadata)->toHaveKey('source_key');
});

it('forbids retrying processing without admin permission', function () {
    bindFakeProvider();

    $user = new User(['id' => 1]);
    $user->id = 1;
    $user->deniedPermissions = ['videoLogsAdmin'];
    test()->actingAs($user);

    $videoLog = createVideoLog(['status' => 'failed']);

    $response = $this->postJson("video-logs/{$videoLog->id}/retry_processing");

    $response->assertForbidden();

    expect($videoLog->fresh()->status)->toBe('failed');
});

it('does not retry processing for a video log that has not failed', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog(['status' => 'ready']);

    $response = $this->postJson("video-logs/{$videoLog->id}/retry_processing");

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($fake->dispatchProcessingCalls)->toHaveCount(0)
        ->and($videoLog->fresh()->status)->toBe('ready');
});

it('checks status and marks a processing video log ready', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'processing',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'mediaconvert_job_id' => 'job-123',
            'mediaconvert_status' => 'COMPLETE',
        ],
    ]);

    $response = $this->postJson("video-logs/{$videoLog->id}/check_status");

    $response->assertOk()
        ->assertJsonPath('status', 'ready');

    expect($fake->checkStatusCalls)->toHaveCount(1)
        ->and($videoLog->fresh()->status)->toBe('ready');
});

it('checks status and marks a processing video log failed', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'processing',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'mediaconvert_job_id' => 'job-123',
            'mediaconvert_status' => 'ERROR',
            'mediaconvert_error_code' => 1404,
            'mediaconvert_error_message' => 'Unable to open input file',
        ],
    ]);

    $response = $this->postJson("video-logs/{$videoLog->id}/check_status");

    $response->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('provider_metadata.failure.reason', 'transcode_error')
        ->assertJsonPath('provider_metadata.failure.code', 1404)
        ->assertJsonPath('provider_metadata.failure.message', 'Unable to open input file');

    expect($fake->checkStatusCalls)->toHaveCount(1)
        ->and($videoLog->fresh()->status)->toBe('failed');
});

it('leaves a still-processing video log unchanged when checking status', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'processing',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'mediaconvert_job_id' => 'job-123',
            'mediaconvert_status' => 'PROGRESSING',
        ],
    ]);

    $response = $this->postJson("video-logs/{$videoLog->id}/check_status");

    $response->assertOk()
        ->assertJsonPath('status', 'processing');

    expect($fake->checkStatusCalls)->toHaveCount(1)
        ->and($videoLog->fresh()->status)->toBe('processing');
});

it('forbids checking status without admin permission', function () {
    bindFakeProvider();

    $user = new User(['id' => 1]);
    $user->id = 1;
    $user->deniedPermissions = ['videoLogsAdmin'];
    test()->actingAs($user);

    $videoLog = createVideoLog(['status' => 'processing']);

    $response = $this->postJson("video-logs/{$videoLog->id}/check_status");

    $response->assertForbidden();

    expect($videoLog->fresh()->status)->toBe('processing');
});

it('does not check status for a video log that is not processing', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog(['status' => 'ready']);

    $response = $this->postJson("video-logs/{$videoLog->id}/check_status");

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($fake->checkStatusCalls)->toHaveCount(0)
        ->and($videoLog->fresh()->status)->toBe('ready');
});

it('updates a video log title', function () {
    bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog(['title' => 'Old title']);

    $response = $this->patchJson("video-logs/{$videoLog->id}", [
        'title' => 'New title',
    ]);

    $response->assertOk()
        ->assertJsonPath('title', 'New title');

    expect($videoLog->fresh()->title)->toBe('New title');
});

it('destroys a video log and deletes provider assets', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog();

    $response = $this->deleteJson("video-logs/{$videoLog->id}");

    $response->assertOk();

    expect($fake->deleteAssetsCalls)->toHaveCount(1)
        ->and(VideoLog::withTrashed()->find($videoLog->id))->toBeNull();
});

it('handles provider webhooks through the interface', function () {
    $fake = bindFakeProvider();

    $videoLog = createVideoLog(['status' => 'processing']);

    $response = $this->postJson('video-logs/webhook', [
        'detail' => [
            'status' => 'COMPLETE',
            'userMetadata' => [
                'video_log_id' => (string) $videoLog->id,
            ],
        ],
    ]);

    $response->assertNoContent();

    expect($fake->handleWebhookCalls)->toHaveCount(1)
        ->and($videoLog->fresh()->status)->toBe('ready');
});

it('finalizes a multipart upload with parts payload', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog(['status' => 'pending']);

    $parts = [
        ['part_number' => 1, 'etag' => '"etag-1"'],
        ['part_number' => 2, 'etag' => '"etag-2"'],
    ];

    $response = $this->postJson("video-logs/{$videoLog->id}/finalize_upload", [
        'parts' => $parts,
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'processing');

    expect($fake->finalizeUploadCalls)->toHaveCount(1)
        ->and($fake->finalizeUploadCalls[0]['payload']['parts'])->toBe($parts)
        ->and($fake->dispatchProcessingCalls)->toHaveCount(1);
});

it('signs upload parts through the provider', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'pending',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'upload_id' => 'upload-abc',
            'part_size' => 10_485_760,
            'part_count' => 3,
        ],
    ]);

    $response = $this->postJson("video-logs/{$videoLog->id}/upload_parts", [
        'part_numbers' => [2, 3],
    ]);

    $response->assertOk()
        ->assertJsonPath('parts.0.part_number', 2)
        ->assertJsonPath('parts.1.part_number', 3);

    expect($fake->signUploadPartsCalls)->toHaveCount(1)
        ->and($fake->signUploadPartsCalls[0]['partNumbers'])->toBe([2, 3]);
});

it('returns upload status for a multipart upload', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'pending',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'upload_id' => 'upload-abc',
            'part_size' => 10_485_760,
            'part_count' => 2,
            'uploaded_parts' => [
                ['part_number' => 1, 'etag' => '"etag-1"', 'size' => 10_485_760],
            ],
        ],
    ]);

    $response = $this->getJson("video-logs/{$videoLog->id}/upload_status");

    $response->assertOk()
        ->assertJsonPath('multipart', true)
        ->assertJsonPath('upload_id', 'upload-abc')
        ->assertJsonPath('parts.0.part_number', 1);

    expect($fake->uploadStatusCalls)->toHaveCount(1);
});

it('aborts an in-progress upload through the provider', function () {
    $fake = bindFakeProvider();
    actingAsUser();

    $videoLog = createVideoLog([
        'status' => 'pending',
        'provider_metadata' => [
            'source_key' => 'fake/source/1',
            'upload_id' => 'upload-abc',
            'part_size' => 10_485_760,
            'part_count' => 2,
        ],
    ]);

    $response = $this->postJson("video-logs/{$videoLog->id}/abort_upload");

    $response->assertNoContent();

    expect($fake->abortUploadCalls)->toHaveCount(1)
        ->and($videoLog->fresh()->provider_metadata)->not->toHaveKey('upload_id');
});
