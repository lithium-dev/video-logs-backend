<?php

namespace Lithium\VideoLogs;

use Aws\MediaConvert\MediaConvertClient;
use Aws\S3\S3Client;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Jobs\ReconcileProcessingVideoLogs;
use Lithium\VideoLogs\Models\VideoLog;
use Lithium\VideoLogs\Policies\VideoLogPolicy;
use Lithium\VideoLogs\Services\S3VideoStorageProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class VideoLogsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('video-logs')
            ->hasConfigFile()
            ->hasMigrations(
                [
                    'create_video_logs_table',
                ]
            )
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->bind(VideoStorageProvider::class, function ($app) {
            $config = config('video-logs.providers.s3');

            return new S3VideoStorageProvider(
                s3: $this->makeS3Client($config),
                mediaConvert: $this->makeMediaConvertClient($config),
                config: $config,
            );
        });
    }

    public function packageBooted(): void
    {
        Gate::policy(VideoLog::class, VideoLogPolicy::class);

        // Only the route prefix is applied here. Middleware is defined per-group
        // inside the routes file: user-facing routes apply
        // config('video-logs.middleware'), while the webhook route stays
        // unauthenticated (it is verified by SNS signature instead). Applying the
        // auth middleware to the whole group here would also cover the webhook and
        // redirect unauthenticated SNS deliveries to login (HTTP 302).
        Route::group([
            'prefix' => config('video-logs.route_prefix', 'api'),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        $this->registerScheduledReconcile();
    }

    /**
     * Schedule the sweep that keeps video logs from being stranded in the
     * "processing" state. Registered via callAfterResolving so it only wires
     * up when the scheduler is actually in play (i.e. the console), and can be
     * turned off entirely through config.
     */
    private function registerScheduledReconcile(): void
    {
        if (! config('video-logs.processing.reconcile_enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new ReconcileProcessingVideoLogs)
                ->everyFifteenMinutes()
                ->name('video-logs:reconcile-processing')
                ->withoutOverlapping();
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeS3Client(array $config): S3Client
    {
        return new S3Client($this->awsClientArgs($config));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeMediaConvertClient(array $config): MediaConvertClient
    {
        $args = $this->awsClientArgs($config);

        if (! empty($config['mediaconvert_endpoint'])) {
            $args['endpoint'] = $config['mediaconvert_endpoint'];
        }

        return new MediaConvertClient($args);
    }

    /**
     * Base AWS SDK client arguments shared by the S3 and MediaConvert clients.
     *
     * When a complete credential pair is configured it is passed explicitly so
     * the module authenticates as its own least-privilege IAM user, kept
     * separate from the host app's general file/image storage. When absent, the
     * args omit credentials entirely and the SDK falls back to the default
     * credential chain (env vars, shared profile, or an instance/task role).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function awsClientArgs(array $config): array
    {
        $args = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];

        $credentials = $this->resolveCredentials($config);
        if ($credentials !== null) {
            $args['credentials'] = $credentials;
        }

        return $args;
    }

    /**
     * Resolve an AWS credential pair atomically.
     *
     * The dedicated video-logs pair (VIDEO_LOGS_S3_KEY / VIDEO_LOGS_S3_SECRET)
     * is used only when BOTH are present; otherwise BOTH fall back to the host
     * app's AWS_* pair (single-user setups). This guarantees the key and secret
     * always originate from the SAME IAM user — pairing a key from one user with
     * a secret from another produces a SignatureDoesNotMatch on every request.
     * Returns null when no complete pair is configured so the SDK default
     * credential chain takes over.
     *
     * @param  array<string, mixed>  $config
     * @return array{key: string, secret: string}|null
     */
    private function resolveCredentials(array $config): ?array
    {
        if (! empty($config['key']) && ! empty($config['secret'])) {
            return [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        if (! empty($config['fallback_key']) && ! empty($config['fallback_secret'])) {
            return [
                'key' => $config['fallback_key'],
                'secret' => $config['fallback_secret'],
            ];
        }

        return null;
    }
}
