<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class TestNotification extends Notification
{
    public bool $ignoreSubscriptions = false;

    public array $optIn = [];

    public ?Model $scope = null;

    public function via($notifiable): array
    {
        return ['recording'];
    }

    public function getOptInSubscriptions(): array
    {
        return $this->optIn;
    }

    public function getSubscriptionModel($notifiable): ?Model
    {
        return $this->scope;
    }
}
