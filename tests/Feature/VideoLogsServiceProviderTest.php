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

it('omits explicit credentials when only one of key/secret is present', function () {
    expect(awsClientArgsFor(['key' => 'only-key', 'secret' => null]))->not->toHaveKey('credentials');
    expect(awsClientArgsFor(['key' => null, 'secret' => 'only-secret']))->not->toHaveKey('credentials');
});
