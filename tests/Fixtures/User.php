<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Lacodix\LaravelNotificationSubscriptions\Traits\HasNotificationSubscriptions;

class User extends Model
{
    use HasNotificationSubscriptions;
    use Notifiable;

    protected $guarded = [];
}
