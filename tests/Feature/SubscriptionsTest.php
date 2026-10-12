<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Lacodix\LaravelNotificationSubscriptions\Models\NotificationSubscription;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;

class SubscriptionsTest extends TestCase
{
    public function test_provider_registers_migrations_and_default_configuration(): void
    {
        $this->assertTrue(Schema::hasTable('notification_subscriptions'));
        $this->assertSame(['database'], config('notification-subscriptions.excluded_channels'));
    }

    public function test_subscribe_unsubscribe_and_resubscribe_reuse_the_same_record(): void
    {
        $user = User::query()->create();
        $subscription = $user->subscribe(TestNotification::class);

        $this->assertSame('*', $subscription->channel);
        $this->assertSame(TestNotification::class, $subscription->type);
        $this->assertTrue($subscription->isSubscribed());
        $this->assertTrue($subscription->notifiable->is($user));

        $user->unsubscribe(TestNotification::class);
        $this->assertNotNull($subscription->refresh()->unsubscribed_at);
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail'));

        $user->subscribe(TestNotification::class);
        $this->assertNull($subscription->refresh()->unsubscribed_at);
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertSame(1, NotificationSubscription::query()->count());
    }

    public function test_unsubscribe_can_create_a_record_and_channels_override_wildcards(): void
    {
        $user = User::query()->create();
        $user->unsubscribe(TestNotification::class);
        $user->subscribe(TestNotification::class, 'mail');
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms'));

        $user->subscribe(TestNotification::class);
        $user->unsubscribe(TestNotification::class, 'mail');
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'sms'));
        $this->assertSame(2, $user->notificationSubscriptions()->count());
    }

    public function test_opt_in_requires_an_explicit_subscription(): void
    {
        $user = User::query()->create();
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', null, ['sms']));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
        $user->subscribe(TestNotification::class);
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
        $user->unsubscribe(TestNotification::class, 'sms');
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
    }

    public function test_scoped_subscriptions_and_reset_are_isolated_by_type_scope_and_notifiable(): void
    {
        $user = User::query()->create();
        $otherUser = User::query()->create();
        $scope = User::query()->create();
        $otherScope = User::query()->create();
        $user->unsubscribe(TestNotification::class, '*', $scope);
        $user->unsubscribe(TestNotification::class, 'mail', $scope);
        $user->unsubscribe('another notification', '*', $scope);
        $user->unsubscribe(TestNotification::class);
        $user->subscribe(TestNotification::class, '*', $otherScope);
        $otherUser->unsubscribe(TestNotification::class, '*', $scope);

        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $otherScope));
        $this->assertTrue($user->findSubscription(TestNotification::class, '*', $scope)->model->is($scope));
        $this->assertSame(3, $user->notificationSubscriptions()->model($scope)->count());
        $this->assertSame($user, $user->resetSubscriptions(TestNotification::class, $scope));
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertFalse($otherUser->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertFalse($user->isSubscribed('another notification', 'mail', $scope));
        $this->assertNotNull($user->findSubscription(TestNotification::class, '*', $otherScope));
    }

    public function test_reset_can_be_chained_and_only_removes_the_requested_notification(): void
    {
        $user = User::query()->create();
        $user->unsubscribe(TestNotification::class);
        $user->unsubscribe(TestNotification::class, 'mail');
        $user->unsubscribe('another notification');

        $user->resetSubscriptions(TestNotification::class)->subscribe(TestNotification::class);

        $this->assertSame(2, $user->notificationSubscriptions()->count());
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertFalse($user->isSubscribed('another notification', 'mail'));
    }
}
