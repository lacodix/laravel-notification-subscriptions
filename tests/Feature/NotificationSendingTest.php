<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Lacodix\LaravelNotificationSubscriptions\Events\NotificationSuppressed;
use Lacodix\LaravelNotificationSubscriptions\Listeners\NotificationSendingListener;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\RecordingChannel;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class NotificationSendingTest extends TestCase
{
    public static function relationStates(): array
    {
        return ['cold' => [false], 'complete eager load' => [true]];
    }

    #[DataProvider('relationStates')]
    public function test_global_unsubscribe_suppresses_real_model_scoped_sending(bool $loaded): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $user->unsubscribe(TestNotification::class);
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }
        $notification = new TestNotification();
        $notification->scope = $scope;
        $sent = [];
        $suppressed = [];
        $this->app['events']->listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = $event;
        });
        $this->app['events']->listen(NotificationSuppressed::class, function ($event) use (&$suppressed) {
            $suppressed[] = $event;
        });

        $this->assertNull($user->findSubscription(TestNotification::class, '*', $scope), 'Exact lookup has no global fallback.');
        $user->notify($notification);

        $this->assertCount(0, $this->app->make(RecordingChannel::class)->sent);
        $this->assertCount(0, $sent);
        $this->assertCount(1, $suppressed);
        $this->assertSame($suppressed[0]->event->notification, $suppressed[0]->notification);
        $this->assertSame($scope, $suppressed[0]->notification->scope);
        $this->assertSame($user, $suppressed[0]->notifiable);
        $this->assertSame('recording', $suppressed[0]->channel);
    }

    #[DataProvider('relationStates')]
    public function test_explicit_scoped_opt_in_overrides_global_unsubscribe_until_reset(bool $loaded): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $user->unsubscribe(TestNotification::class);
        $user->subscribe(TestNotification::class, '*', $scope);
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }
        $notification = new TestNotification();
        $notification->scope = $scope;
        $notification->optIn = ['recording'];
        $suppressed = [];
        $this->app['events']->listen(NotificationSuppressed::class, function ($event) use (&$suppressed) {
            $suppressed[] = $event;
        });

        $user->notify($notification);
        $this->assertCount(1, $this->app->make(RecordingChannel::class)->sent);
        $this->assertCount(0, $suppressed);

        $user->resetSubscriptions(TestNotification::class, $scope);
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }
        $notification->optIn = [];
        $user->notify($notification);
        $this->assertCount(1, $this->app->make(RecordingChannel::class)->sent);
        $this->assertCount(1, $suppressed);
    }

    public function test_subscribed_sending_and_unsubscribe_suppression_use_real_events(): void
    {
        $user = User::query()->create();
        $user->subscribe(TestNotification::class, 'recording');
        $sent = [];
        $suppressed = [];
        $this->app['events']->listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = $event;
        });
        $this->app['events']->listen(NotificationSuppressed::class, function ($event) use (&$suppressed) {
            $suppressed[] = $event;
        });

        $user->notify(new TestNotification());
        $this->assertCount(1, $this->app->make(RecordingChannel::class)->sent);
        $this->assertCount(1, $sent);
        $this->assertCount(0, $suppressed);

        $user->unsubscribe(TestNotification::class, 'recording');
        $user->notify(new TestNotification());
        $this->assertCount(1, $this->app->make(RecordingChannel::class)->sent);
        $this->assertCount(1, $sent);
        $this->assertCount(1, $suppressed);
        $this->assertInstanceOf(NotificationSending::class, $suppressed[0]->event);
        $this->assertSame($user, $suppressed[0]->notifiable);
        $this->assertSame($suppressed[0]->event->notification, $suppressed[0]->notification);
        $this->assertSame('recording', $suppressed[0]->channel);
        $this->assertFalse((new NotificationSendingListener())->handle(
            new NotificationSending($user, new TestNotification(), 'recording')
        ));
    }

    public function test_opt_in_suppression_then_explicit_subscription_allows_sending(): void
    {
        $user = User::query()->create();
        $notification = new TestNotification();
        $notification->optIn = ['recording'];
        $user->notify($notification);
        $this->assertCount(0, $this->app->make(RecordingChannel::class)->sent);

        $user->subscribe(TestNotification::class);
        $user->notify($notification);
        $this->assertCount(1, $this->app->make(RecordingChannel::class)->sent);
    }

    public function test_notification_scope_is_respected_by_real_sending(): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $otherScope = User::query()->create();
        $user->unsubscribe(TestNotification::class, '*', $scope);
        $notification = new TestNotification();
        $notification->scope = $scope;
        $user->notify($notification);
        $this->assertCount(0, $this->app->make(RecordingChannel::class)->sent);

        $notification->scope = $otherScope;
        $user->notify($notification);
        $this->assertCount(1, $this->app->make(RecordingChannel::class)->sent);
    }
}
