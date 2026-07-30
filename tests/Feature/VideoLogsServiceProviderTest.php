<?php

use Aws\MediaConvert\MediaConvertClient;
use Aws\S3\S3Client;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Services\S3VideoStorageProvider;
use Lithium\VideoLogs\VideoLogsServiceProvider;

/**
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function awsClientArgsFor(array $config): array
{
    $provider = new VideoLogsServiceProvider(app());

    $method = new ReflectionMethod(VideoLogsServiceProvider::class, 'awsClientArgs');
    $method->setAccessible(true);

    return $method->invoke($provider, $config);
}

function resolveS3Client(): S3Client
{
    $provider = app(VideoStorageProvider::class);
    expect($provider)->toBeInstanceOf(S3VideoStorageProvider::class);

    $reflection = new ReflectionProperty(S3VideoStorageProvider::class, 's3');
    $reflection->setAccessible(true);

    return $reflection->getValue($provider);
}

function resolveMediaConvertClient(): MediaConvertClient
{
    $provider = app(VideoStorageProvider::class);

    $reflection = new ReflectionProperty(S3VideoStorageProvider::class, 'mediaConvert');
    $reflection->setAccessible(true);

    return $reflection->getValue($provider);
}

it('authenticates AWS clients with the dedicated video-logs credentials when configured', function () {
    config()->set('video-logs.providers.s3.key', 'video-logs-key');
    config()->set('video-logs.providers.s3.secret', 'video-logs-secret');

    $s3Credentials = resolveS3Client()->getCredentials()->wait();
    expect($s3Credentials->getAccessKeyId())->toBe('video-logs-key');
    expect($s3Credentials->getSecretKey())->toBe('video-logs-secret');

    $mediaConvertCredentials = resolveMediaConvertClient()->getCredentials()->wait();
    expect($mediaConvertCredentials->getAccessKeyId())->toBe('video-logs-key');
    expect($mediaConvertCredentials->getSecretKey())->toBe('video-logs-secret');
});

it('omits explicit credentials so the SDK uses its default chain when none are configured', function () {
    $args = awsClientArgsFor(['region' => 'us-east-1', 'key' => null, 'secret' => null]);

    expect($args)->not->toHaveKey('credentials');
    expect($args['region'])->toBe('us-east-1');
});

it('falls back to the AWS_* pair as a matched set when the dedicated pair is incomplete', function () {
    // Only the dedicated key is set (secret missing). The credentials must NOT
    // mix the dedicated key with the AWS_* secret — both come from AWS_* instead,
    // otherwise the mismatched pair signs every request with SignatureDoesNotMatch.
    $args = awsClientArgsFor([
        'key' => 'video-logs-key',
        'secret' => null,
        'fallback_key' => 'aws-key',
        'fallback_secret' => 'aws-secret',
    ]);

    expect($args['credentials'])->toBe(['key' => 'aws-key', 'secret' => 'aws-secret']);
});

it('prefers the dedicated pair over the AWS_* fallback when both are complete', function () {
    $args = awsClientArgsFor([
        'key' => 'video-logs-key',
        'secret' => 'video-logs-secret',
        'fallback_key' => 'aws-key',
        'fallback_secret' => 'aws-secret',
    ]);

    expect($args['credentials'])->toBe(['key' => 'video-logs-key', 'secret' => 'video-logs-secret']);
});

it('omits explicit credentials when neither a dedicated nor a fallback pair is complete', function () {
    expect(awsClientArgsFor(['key' => 'only-key', 'secret' => null]))->not->toHaveKey('credentials');
    expect(awsClientArgsFor(['key' => null, 'secret' => 'only-secret']))->not->toHaveKey('credentials');
    expect(awsClientArgsFor(['fallback_key' => 'aws-key', 'fallback_secret' => null]))->not->toHaveKey('credentials');
});
