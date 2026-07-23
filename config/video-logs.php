<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage / Delivery Provider
    |--------------------------------------------------------------------------
    |
    | The active provider used for storing, transcoding, and delivering video
    | logs. The package is provider-agnostic; add additional providers under
    | the "providers" array below and switch by changing this value.
    |
    */

    'provider' => env('VIDEO_LOGS_PROVIDER', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Per-provider configuration. Each provider defines its own connection,
    | delivery, and transcoding settings.
    |
    */

    'providers' => [

        's3' => [
            'bucket' => env('VIDEO_LOGS_S3_BUCKET'),
            'region' => env('VIDEO_LOGS_S3_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),

            // Dedicated credentials for the video-logs IAM user. Prefer setting
            // VIDEO_LOGS_S3_KEY / VIDEO_LOGS_S3_SECRET so video logs authenticate
            // as their own least-privilege user, leaving the host app's
            // AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY (used for its general
            // file/image bucket) untouched. Falls back to the standard AWS vars
            // for single-user setups; when neither is set the SDK uses its
            // default credential chain (e.g. an instance/task role).
            'key' => env('VIDEO_LOGS_S3_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('VIDEO_LOGS_S3_SECRET', env('AWS_SECRET_ACCESS_KEY')),

            'signed_url_ttl' => (int) env('VIDEO_LOGS_SIGNED_URL_TTL', 3600),
            'upload_ttl' => (int) env('VIDEO_LOGS_UPLOAD_TTL', 3600),

            // Files at or above this size use S3 multipart upload (resumable).
            // Below it, a single presigned PUT is used. Default 25 MB.
            'multipart_threshold_bytes' => (int) env('VIDEO_LOGS_MULTIPART_THRESHOLD_BYTES', 25 * 1024 * 1024),

            // Size of each multipart chunk. Must be at least 5 MB (S3 minimum
            // for all but the last part). Default 10 MB.
            'multipart_part_size_bytes' => (int) env('VIDEO_LOGS_MULTIPART_PART_SIZE_BYTES', 10 * 1024 * 1024),

            'mediaconvert_role' => env('VIDEO_LOGS_MEDIACONVERT_ROLE'),
            'mediaconvert_endpoint' => env('VIDEO_LOGS_MEDIACONVERT_ENDPOINT'),

            'output_prefix' => env('VIDEO_LOGS_OUTPUT_PREFIX', 'video-logs/'),

            // Verify the SNS signature on inbound webhook calls. Keep true in
            // production; disable only for local testing with a tunneled endpoint.
            'verify_webhook_signature' => (bool) env('VIDEO_LOGS_VERIFY_WEBHOOK_SIGNATURE', true),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Constraints
    |--------------------------------------------------------------------------
    |
    | Limits applied to incoming video log uploads. "max_duration_seconds"
    | caps the recording length, "max_file_bytes" caps the raw file size.
    |
    */

    'max_duration_seconds' => (int) env('VIDEO_LOGS_MAX_DURATION_SECONDS', 300),

    'max_file_size_kb' => (int) env('VIDEO_LOGS_MAX_FILE_SIZE_KB', 512000),

    'allowed_mime_types' => [
        'video/mp4',
        'video/quicktime',
    ],

    'max_file_bytes' => env('VIDEO_LOGS_MAX_FILE_BYTES') !== null
        ? (int) env('VIDEO_LOGS_MAX_FILE_BYTES')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep video logs before pruning. Set to null to keep
    | video logs forever.
    |
    */

    'retention_days' => env('VIDEO_LOGS_RETENTION_DAYS') !== null
        ? (int) env('VIDEO_LOGS_RETENTION_DAYS')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Processing Reconciliation
    |--------------------------------------------------------------------------
    |
    | A scheduled job reconciles video logs stuck in the "processing" state so
    | they don't hang forever when a provider completion webhook never arrives.
    |
    | - "reconcile_enabled" registers (or skips) the scheduled job entirely.
    | - "reconcile_after_minutes" is how long a log must have been processing
    |   before the job pulls its status directly from the provider.
    | - "timeout_minutes" is the hard cap: a log still processing beyond this
    |   is marked failed so the UI stops waiting on it.
    |
    */

    'processing' => [
        'reconcile_enabled' => (bool) env('VIDEO_LOGS_RECONCILE_ENABLED', true),
        'reconcile_after_minutes' => (int) env('VIDEO_LOGS_RECONCILE_AFTER_MINUTES', 5),
        'timeout_minutes' => (int) env('VIDEO_LOGS_PROCESSING_TIMEOUT_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | The route prefix and middleware applied to the package's routes.
    |
    */

    'route_prefix' => env('VIDEO_LOGS_ROUTE_PREFIX', 'api'),

    'middleware' => ['api', 'auth:sanctum'],

    'permission_key' => env('VIDEO_LOGS_PERMISSION_KEY', 'videoLogs'),

    /*
    |--------------------------------------------------------------------------
    | Uploader
    |--------------------------------------------------------------------------
    |
    | The host app's user model and the column holding the user's display name.
    | These are used to resolve the uploader's name for a video log (via a
    | subquery on index and the relationship on show) without the package
    | hard-coding the host application's user model.
    |
    */

    'user' => [
        'model' => 'App\\Models\\User',
        'name_column' => 'name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Permission Key
    |--------------------------------------------------------------------------
    |
    | Permission resource used to guard privileged actions such as retrying
    | processing for a video log that failed to transcode. This is separate
    | from the day-to-day "permission_key" so it can be restricted to admins.
    |
    */

    'admin_permission_key' => env('VIDEO_LOGS_ADMIN_PERMISSION_KEY', 'videoLogsAdmin'),

];
