<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Lacodix\LaravelNotificationSubscriptions\Events\NotificationSuppressed;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\RecordingChannel;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EagerSubscriptionTest extends TestCase
{
    public static function senderScopes(): array
    {
        return ['unscoped' => [false], 'scoped' => [true]];
    }

    #[DataProvider('senderScopes')]
    public function test_real_sender_uses_complete_eager_relations_without_subscription_sql(bool $scoped): void
    {
        $first = User::query()->create();
        $second = User::query()->create();
        $third = User::query()->create();
        $scope = User::query()->create();
        $first->unsubscribe(TestNotification::class);
        $first->unsubscribe(TestNotification::class, '*', $scope);
        $second->subscribe(TestNotification::class, 'recording');
        $second->subscribe(TestNotification::class, 'recording', $scope);
        $notification = new TestNotification();
        $notification->scope = $scoped ? $scope : null;
        $sent = [];
        $suppressed = [];
        $this->app['events']->listen(NotificationSent::class, function ($event) use (&$sent) {
            $sent[] = $event;
        });
        $this->app['events']->listen(NotificationSuppressed::class, function ($event) use (&$suppressed) {
            $suppressed[] = $event;
        });

        DB::enableQueryLog();
        DB::flushQueryLog();
        $users = User::with('notificationSubscriptions')->whereKey([$first->id, $second->id, $third->id])->orderBy('id')->get();
        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(0, $users[2]->notificationSubscriptions, 'The default recipient has a complete empty cache.');
        DB::flushQueryLog();

        Notification::send($users, $notification);

        $recorded = $this->app->make(RecordingChannel::class)->sent;
        $this->assertCount(2, $recorded);
        $this->assertSame($users[1], $recorded[0][0]);
        $this->assertSame($users[2], $recorded[1][0]);
        $this->assertCount(2, $sent);
        $this->assertCount(1, $suppressed);
        $this->assertSame($users[0], $suppressed[0]->notifiable);
        $this->assertCount(0, DB::getQueryLog(), 'Actual sending and suppression must use the eager-loaded preferences without SQL.');
    }

    public function test_loaded_scopes_wildcards_opt_in_and_misses_need_no_sql(): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $otherScope = User::query()->create();
        $user->unsubscribe(TestNotification::class, '*', $scope);
        $user->subscribe(TestNotification::class, 'sms', $scope);
        $user->subscribe('other type', '*', $otherScope);
        $user->load('notificationSubscriptions');

        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $scope));
            $this->assertTrue($user->isSubscribed(TestNotification::class, 'sms', $scope, ['sms']));
            $this->assertFalse($user->isSubscribed(TestNotification::class, 'sms', $otherScope, ['sms']));
            $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $otherScope));
            $this->assertNull($user->findSubscription(TestNotification::class, 'mail', $scope));
            $this->assertNull($user->findSubscription(TestNotification::class));
        }
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_cold_lookup_stays_a_scoped_query_without_loading_the_relation(): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $record = $user->unsubscribe(TestNotification::class, 'mail', $scope);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($record->id, $user->findSubscription(TestNotification::class, 'mail', $scope)->id);
        $this->assertCount(1, DB::getQueryLog());
        $this->assertStringContainsString('"channel" = ?', DB::getQueryLog()[0]['query']);
        $this->assertStringContainsString('"model_id" = ?', DB::getQueryLog()[0]['query']);
        $this->assertFalse($user->relationLoaded('notificationSubscriptions'));
    }

    public function test_complete_eager_loading_avoids_subscription_queries_for_multiple_recipients(): void
    {
        $first = User::query()->create();
        $second = User::query()->create();
        User::query()->create();
        $first->unsubscribe(TestNotification::class);
        $second->unsubscribe(TestNotification::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $users = User::with('notificationSubscriptions')->orderBy('id')->get();
        $this->assertCount(2, DB::getQueryLog(), 'One users query and one eager subscription query.');
        DB::flushQueryLog();
        foreach ($users as $index => $user) {
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $this->assertSame($index === 2, $user->isSubscribed(TestNotification::class, 'mail'));
                $this->assertNull($user->findSubscription(TestNotification::class, 'missing'));
            }
        }
        $this->assertCount(0, DB::getQueryLog(), 'Loaded relations, including an empty relation and misses, must cause no SQL.');
    }
}
