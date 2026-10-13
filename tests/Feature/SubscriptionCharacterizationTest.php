<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\SubscriptionScope;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionCharacterizationTest extends TestCase
{
    public static function relationStates(): array
    {
        return ['cold' => [false], 'complete eager load' => [true]];
    }

    #[DataProvider('relationStates')]
    public function test_channel_priority_defaults_and_local_preference_changes(bool $loaded): void
    {
        $user = User::query()->create();
        if ($loaded) {
            $user->load('notificationSubscriptions');
            $this->assertCount(0, $user->notificationSubscriptions);
        }
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
        $this->assertNull($user->findSubscription(TestNotification::class));

        $wildcard = $user->unsubscribe(TestNotification::class);
        $channel = $user->subscribe(TestNotification::class, 'sms');
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertNull($user->findSubscription(TestNotification::class, 'mail'), 'Exact lookup must not return the wildcard.');

        $this->assertNull($user->subscribe(TestNotification::class));
        $this->assertNull($user->unsubscribe(TestNotification::class, 'sms'));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms'));
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertSame($wildcard->id, $user->findSubscription(TestNotification::class)->id);
        $this->assertSame($channel->id, $user->findSubscription(TestNotification::class, 'sms')->id);
        $this->assertSame(2, $user->notificationSubscriptions()->count());

        $this->assertSame($user, $user->resetSubscriptions(TestNotification::class));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
        $user->resetSubscriptions(TestNotification::class)->subscribe(TestNotification::class);
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'sms', null, ['sms']));
    }

    #[DataProvider('relationStates')]
    public function test_exact_scope_storage_and_reset_isolate_class_id_type_and_recipient(bool $loaded): void
    {
        $user = User::query()->create();
        $otherUser = User::query()->create();
        $scope = new SubscriptionScope(['id' => 12, 'scope_key' => 99]);
        $sameScope = new SubscriptionScope(['id' => '12', 'scope_key' => 100]);
        $otherScope = new SubscriptionScope(['id' => 13, 'scope_key' => 99]);
        $otherClass = new User(['id' => 12]);
        $unsavedScope = new SubscriptionScope();
        $zeroScope = new SubscriptionScope(['id' => 0]);
        $record = $user->unsubscribe(TestNotification::class, '*', $scope);
        $user->unsubscribe('other type', '*', $scope);
        $user->unsubscribe(TestNotification::class, '*', $otherScope);
        $user->unsubscribe(TestNotification::class, '*', $otherClass);
        $user->subscribe(TestNotification::class, '*', $unsavedScope);
        $user->unsubscribe(TestNotification::class, '*', $zeroScope);
        $otherUser->subscribe(TestNotification::class, '*', $scope);
        if ($loaded) {
            $user->load('notificationSubscriptions');
            $otherUser->load('notificationSubscriptions');
        }

        $this->assertSame(SubscriptionScope::class, $record->model_type);
        $this->assertEquals(12, $record->model_id, 'Storage uses id, not the custom key or morph alias.');
        $this->assertSame($record->id, $user->findSubscription(TestNotification::class, '*', $sameScope)->id);
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $sameScope));
        $this->assertTrue($otherUser->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertNull($user->findSubscription(TestNotification::class));
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $unsavedScope));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $zeroScope));

        $this->assertSame($user, $user->resetSubscriptions(TestNotification::class, $sameScope));
        $this->assertNull($user->findSubscription(TestNotification::class, '*', $scope));
        $this->assertFalse($user->isSubscribed('other type', 'mail', $scope));
        $this->assertNotNull($user->findSubscription(TestNotification::class, '*', $otherScope));
        $this->assertNotNull($user->findSubscription(TestNotification::class, '*', $otherClass));
        $this->assertNotNull($otherUser->findSubscription(TestNotification::class, '*', $scope));
        // Global fallback after a scoped reset is an intentional #6 behavior delta,
        // covered by the separate regression rather than frozen here.
    }
}
