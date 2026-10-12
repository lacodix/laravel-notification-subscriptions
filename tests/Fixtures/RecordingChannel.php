<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures;

class RecordingChannel
{
    public array $sent = [];

    public function send($notifiable, $notification): void
    {
        $this->sent[] = [$notifiable, $notification];
    }
}
