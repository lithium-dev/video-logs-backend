<?php

namespace Lithium\VideoLogs\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Lithium\VideoLogs\Models\VideoLog;

class VideoLogReady
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly VideoLog $videoLog,
    ) {}
}
