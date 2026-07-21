<?php

namespace Lithium\VideoLogs\Policies;

use Lithium\VideoLogs\Models\VideoLog;

class VideoLogPolicy
{
    public function viewAny($user): bool
    {
        return $this->hasPermission($user, 'view');
    }

    public function view($user, VideoLog $videoLog): bool
    {
        return $this->hasPermission($user, 'view');
    }

    public function create($user): bool
    {
        return $this->hasPermission($user, 'create');
    }

    public function update($user, VideoLog $videoLog): bool
    {
        return $this->hasPermission($user, 'edit') || $videoLog->user_id === $user->id;
    }

    public function delete($user, VideoLog $videoLog): bool
    {
        return $this->hasPermission($user, 'delete') || $videoLog->user_id === $user->id;
    }

    public function restore($user, VideoLog $videoLog): bool
    {
        return $this->hasPermission($user, 'delete');
    }

    public function forceDelete($user, VideoLog $videoLog): bool
    {
        return $this->hasPermission($user, 'delete');
    }

    public function retryProcessing($user, VideoLog $videoLog): bool
    {
        return $this->hasAdminPermission($user, 'edit');
    }

    public function checkStatus($user, VideoLog $videoLog): bool
    {
        return $this->hasAdminPermission($user, 'edit');
    }

    private function hasPermission($user, string $action): bool
    {
        return method_exists($user, 'hasPermission')
            && $user->hasPermission($action, config('video-logs.permission_key'));
    }

    private function hasAdminPermission($user, string $action): bool
    {
        return method_exists($user, 'hasPermission')
            && $user->hasPermission($action, config('video-logs.admin_permission_key'));
    }
}
