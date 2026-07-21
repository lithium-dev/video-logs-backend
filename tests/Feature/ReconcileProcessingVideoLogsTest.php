<?php

use Lithium\VideoLogs\Jobs\ReconcileProcessingVideoLogs;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Tests\Fixtures\FakeVideoStorageProvider;

function makeProcessingLog(int $updatedMinutesAgo, array $metadata = []): VideoLog
{
    $videoLog = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Site walkthrough',
        'status' => 'processing',
        'provider' => 'fake',
        'provider_metadata' => array_merge(['source_key' => 'fake/source/1'], $metadata),
    ]);

    // Backdate updated_at without touching model timestamps so the log looks
    // like it has been sitting in "processing" for a while.
    VideoLog::query()
        ->whereKey($videoLog->id)
        ->update(['updated_at' => now()->subMinutes($updatedMinutesAgo)]);

    return $videoLog->refresh();
}

it('reconciles a stuck log the provider reports as complete (missed webhook)', function () {
    $fake = new FakeVideoStorageProvider;

    $videoLog = makeProcessingLog(10, ['mediaconvert_status' => 'COMPLETE']);

    (new ReconcileProcessingVideoLogs)->handle($fake);

    expect($fake->checkStatusCalls)->toHaveCount(1)
        ->and($videoLog->refresh()->status)->toBe('ready');
});

it('fails a log stuck in processing past the hard timeout', function () {
    $fake = new FakeVideoStorageProvider;

    // 90 minutes old, provider still has no terminal status to report.
    $videoLog = makeProcessingLog(90);

    (new ReconcileProcessingVideoLogs)->handle($fake);

    $videoLog->refresh();

    expect($videoLog->status)->toBe('failed')
        ->and($videoLog->provider_metadata['failure']['reason'] ?? null)
        ->toBe('processing_timeout');
});

it('leaves a recently-started log untouched until it crosses the reconcile delay', function () {
    $fake = new FakeVideoStorageProvider;

    // Only 1 minute old — below the 5 minute reconcile threshold.
    $videoLog = makeProcessingLog(1, ['mediaconvert_status' => 'COMPLETE']);

    (new ReconcileProcessingVideoLogs)->handle($fake);

    expect($fake->checkStatusCalls)->toBeEmpty()
        ->and($videoLog->refresh()->status)->toBe('processing');
});

it('keeps waiting on a log that is past the reconcile delay but under the timeout', function () {
    $fake = new FakeVideoStorageProvider;

    // 10 minutes old: eligible for a provider check, but nowhere near the
    // 60 minute timeout, and the provider has nothing terminal to report.
    $videoLog = makeProcessingLog(10);

    (new ReconcileProcessingVideoLogs)->handle($fake);

    expect($fake->checkStatusCalls)->toHaveCount(1)
        ->and($videoLog->refresh()->status)->toBe('processing');
});

it('ignores logs that are not processing', function () {
    $fake = new FakeVideoStorageProvider;

    $ready = VideoLog::query()->create([
        'loggable_type' => 'customer',
        'loggable_id' => 1,
        'title' => 'Done',
        'status' => 'ready',
        'provider' => 'fake',
    ]);
    VideoLog::query()->whereKey($ready->id)->update(['updated_at' => now()->subMinutes(120)]);

    (new ReconcileProcessingVideoLogs)->handle($fake);

    expect($fake->checkStatusCalls)->toBeEmpty()
        ->and($ready->refresh()->status)->toBe('ready');
});
