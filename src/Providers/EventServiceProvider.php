<?php

namespace Lacodix\LaravelNotificationSubscriptions\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        'Illuminate\Notifications\Events\NotificationSending' => [
            'Lacodix\LaravelNotificationSubscriptions\Listeners\NotificationSendingListener',
        ],
    ];

    public function boot()
    {
        parent::boot();
    }
}
