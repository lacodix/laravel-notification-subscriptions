<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Illuminate\Notifications\Events\NotificationSending;
use Lacodix\LaravelNotificationSubscriptions\Listeners\NotificationSendingListener;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\PlainUser;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\RecordingChannel;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ListenerCoexistenceTest extends TestCase
{
    public static function allowedPaths(): array
    {
        $cases = [];
        foreach (['no trait', 'excluded channel', 'ignore subscriptions', 'subscribed', 'default'] as $path) {
            foreach ([false, true] as $veto) {
                foreach ([false, true] as $loaded) {
                    $cases[$path.($veto ? ' / veto' : ' / allow').($loaded ? ' / loaded' : ' / cold')] = [$path, $veto, $loaded];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('allowedPaths')]
    public function test_later_application_listener_runs_and_can_veto(string $path, bool $veto, bool $loaded): void
    {
        $user = $path === 'no trait' ? new PlainUser() : User::query()->create();
        $notification = new TestNotification();
        if ($path === 'excluded channel') {
            config(['notification-subscriptions.excluded_channels' => ['recording']]);
            $user->unsubscribe(TestNotification::class);
        } elseif ($path === 'ignore subscriptions') {
            $notification->ignoreSubscriptions = true;
            $user->unsubscribe(TestNotification::class);
        } elseif ($path === 'subscribed') {
            $user->subscribe(TestNotification::class);
        }

        if ($loaded && $user instanceof User) {
            $user->load('notificationSubscriptions');
        }

        $called = [];
        $this->app['events']->listen(NotificationSending::class, function ($event) use (&$called, $veto) {
            $called[] = $event;

            return $veto ? false : null;
        });

        // No Event/Notification fake: Laravel's sender calls the real dispatcher until().
        $user->notify($notification);

        $this->assertCount(1, $called, 'The application listener must run after the package listener.');
        $this->assertSame($user, $called[0]->notifiable);
        $this->assertInstanceOf(TestNotification::class, $called[0]->notification);
        $this->assertCount($veto ? 0 : 1, $this->app->make(RecordingChannel::class)->sent);
        $this->assertNull((new NotificationSendingListener())->handle(
            new NotificationSending($user, $notification, 'recording')
        ));
    }
}
