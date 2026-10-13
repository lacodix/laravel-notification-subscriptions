<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class SubscriptionScope extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'scope_key';

    public function getMorphClass()
    {
        return 'scope-alias';
    }
}
