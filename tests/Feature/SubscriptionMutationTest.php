<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Lacodix\LaravelNotificationSubscriptions\Models\NotificationSubscription;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionMutationTest extends TestCase
{
    public static function hiddenPersistedRows(): array
    {
        return [
            'filtered / subscribe' => ['filtered', true],
            'filtered / unsubscribe' => ['filtered', false],
            'external create / subscribe' => ['external', true],
            'external create / unsubscribe' => ['external', false],
        ];
    }

    #[DataProvider('hiddenPersistedRows')]
    public function test_writes_reuse_a_persisted_row_missing_from_the_loaded_collection(string $cache, bool $subscribe): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $global = $user->unsubscribe(TestNotification::class, 'mail');
        if ($cache === 'external') {
            $user->load('notificationSubscriptions');
            $record = User::findOrFail($user->id)->unsubscribe(TestNotification::class, 'mail', $scope);
        } else {
            $record = $user->unsubscribe(TestNotification::class, 'mail', $scope);
            $user->load(['notificationSubscriptions' => function ($query) {
                $query->where('channel', 'sms');
            }]);
        }

        $result = $subscribe
            ? $user->subscribe(TestNotification::class, 'mail', $scope)
            : $user->unsubscribe(TestNotification::class, 'mail', $scope);

        $this->assertSame(2, $user->notificationSubscriptions()->count(), 'Stale or filtered caches must not cause duplicates.');
        $this->assertNull($result, 'Updating an existing row retains the public return behavior.');
        $this->assertSame($record->id, $user->findSubscription(TestNotification::class, 'mail', $scope)->id);
        $this->assertSame($subscribe, $user->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertFalse($global->refresh()->isSubscribed(), 'The exact scoped write must leave the global row unchanged.');
    }

    public function test_writes_recreate_externally_deleted_cached_rows(): void
    {
        $user = User::query()->create();
        $record = $user->subscribe(TestNotification::class, 'mail');
        $user->load('notificationSubscriptions');
        User::findOrFail($user->id)->resetSubscriptions(TestNotification::class);

        $created = $user->unsubscribe(TestNotification::class, 'mail');

        $this->assertInstanceOf(NotificationSubscription::class, $created);
        $this->assertNotSame($record->id, $created->id);
        $this->assertSame(1, $user->notificationSubscriptions()->count());
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail'));
    }

    public function test_create_and_reset_refresh_local_reads_without_claiming_external_cache_coherence(): void
    {
        $user = User::query()->create();
        $user->load('notificationSubscriptions');
        $created = $user->createSubscription(TestNotification::class, 'mail', null, true);
        $this->assertNotNull($user->findSubscription(TestNotification::class, 'mail'));
        $this->assertSame($created->id, $user->findSubscription(TestNotification::class, 'mail')->id);
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail'));

        $user->load('notificationSubscriptions');
        $otherInstance = User::findOrFail($user->id);
        $otherInstance->subscribe(TestNotification::class, 'mail');
        // A caller reloads after writes through another instance.
        $user->load('notificationSubscriptions');
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail'));
        $this->assertSame($user, $user->resetSubscriptions(TestNotification::class));
        $this->assertNull($user->findSubscription(TestNotification::class, 'mail'));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', null, ['mail']));
    }
}
