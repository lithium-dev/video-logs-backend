<?php

namespace Lithium\VideoLogs\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];

    public $timestamps = false;

    /** @var array<int, string> Permission resources this user is denied. */
    public array $deniedPermissions = [];

    public function hasPermission(string $action, ?string $resource = null): bool
    {
        if ($resource !== null && in_array($resource, $this->deniedPermissions, true)) {
            return false;
        }

        return true;
    }
}
