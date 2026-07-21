<?php

namespace Lithium\VideoLogs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Lithium\VideoLogs\Contracts\VideoStorageProvider;
use Lithium\VideoLogs\Events\VideoLogFailed;

/**
 * @property string $user_name
 */
class VideoLog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'loggable_id',
        'loggable_type',
        'title',
        'description',
        'status',
        'provider',
        'source_key',
        'playback_key',
        'playback_url',
        'poster_key',
        'poster_url',
        'duration_seconds',
        'size_bytes',
        'mime_type',
        'provider_metadata',
        'playback_format',
    ];

    protected function casts()
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'provider_metadata' => 'array',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($videoLog) {
            $videoLog->user_id = auth()->id() ?? null;
        });
    }

    public function loggable()
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('video-logs.user.model'));
    }

    public function scopeSelectAttributes(Builder $query): void
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('video-logs.user.model');
        $nameColumn = config('video-logs.user.name_column', 'name');
        $user = new $userModel;

        $query->addSelect([
            'user_name' => $userModel::query()
                ->select($nameColumn)
                ->whereColumn($user->getQualifiedKeyName(), 'video_logs.user_id')
                ->limit(1),
        ]);
    }

    public function appendAttributes(): void
    {
        $nameColumn = config('video-logs.user.name_column', 'name');
        $this->user_name = $this->user?->{$nameColumn} ?? '';
    }

    /**
     * Mark this video log as failed, persisting the reason/details so they
     * survive beyond the log files, writing them to the application log, and
     * firing the VideoLogFailed event. Provider-specific detail (e.g. the AWS
     * MediaConvert error code/message) is stored under provider_metadata.failure.
     *
     * @param  array{code?: int|string|null, message?: string|null, status?: string|null}  $context
     */
    public function recordFailure(string $reason, array $context = []): void
    {
        $failure = array_filter([
            'reason' => $reason,
            'status' => $context['status'] ?? null,
            'code' => $context['code'] ?? null,
            'message' => $context['message'] ?? null,
            'failed_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');

        $this->update([
            'status' => 'failed',
            'provider_metadata' => array_merge($this->provider_metadata ?? [], [
                'failure' => $failure,
            ]),
        ]);

        Log::error('Video log processing failed.', [
            'video_log_id' => $this->id,
            'mediaconvert_job_id' => $this->provider_metadata['mediaconvert_job_id'] ?? null,
            'reason' => $reason,
            'status' => $context['status'] ?? null,
            'error_code' => $context['code'] ?? null,
            'error_message' => $context['message'] ?? null,
        ]);

        event(new VideoLogFailed($this, reason: $reason));
    }

    public function appendPlayback(VideoStorageProvider $videoStorageProvider): void
    {
        $ttlSeconds = 3600;
        $playbackLink = $this->status != 'ready' ? null : $videoStorageProvider->playbackLink($this, $ttlSeconds);
        $playback = [
            'url' => $playbackLink?->url,
            'format' => $playbackLink?->format,
            'poster_url' => $playbackLink?->posterUrl ?? $this->poster_url,
            'expires_in' => $ttlSeconds,
        ];
        $this->playback = $playback;
    }
}
