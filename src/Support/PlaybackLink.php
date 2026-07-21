<?php

namespace Lithium\VideoLogs\Support;

/** Everything the player needs to play a ready video. */
final class PlaybackLink
{
    public function __construct(
        public readonly string $url,          // mp4 URL now; manifest URL under HLS
        public readonly string $format,       // 'mp4' | 'hls'
        public readonly ?string $posterUrl = null,
        public readonly ?int $durationSeconds = null,
        public readonly int $expiresIn = 3600,
    ) {}
}
