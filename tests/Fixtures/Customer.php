<?php

namespace Lithium\VideoLogs\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    protected $guarded = [];

    public $timestamps = true;
}
