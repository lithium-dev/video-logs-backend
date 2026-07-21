<?php

namespace Lithium\VideoLogs\Concerns;

use Lithium\VideoLogs\Models\VideoLog;

trait HasVideoLogs
{
    public function videoLogs()
    {
        return $this->morphMany(VideoLog::class, 'loggable');
    }
}
