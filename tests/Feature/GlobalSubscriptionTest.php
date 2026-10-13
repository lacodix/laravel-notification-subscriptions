<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\TestNotification;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\User;
use Lacodix\LaravelNotificationSubscriptions\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class GlobalSubscriptionTest extends TestCase
{
    public static function precedenceCases(): array
    {
        // Scoped channel, scoped wildcard, global channel, global wildcard, result.
        $rules = [
            'scoped channel subscribe wins' => [true, false, false, false, true],
            'scoped channel unsubscribe wins' => [false, true, true, true, false],
            'scoped wildcard subscribe wins' => [null, true, false, false, true],
            'scoped wildcard unsubscribe wins' => [null, false, true, true, false],
            'global channel subscribe wins' => [null, null, true, false, true],
            'global channel unsubscribe wins' => [null, null, false, true, false],
            'global wildcard subscribe' => [null, null, null, true, true],
            'global wildcard unsubscribe' => [null, null, null, false, false],
            'scoped channel alone subscribe' => [true, null, null, null, true],
            'scoped channel alone unsubscribe' => [false, null, null, null, false],
            'global channel alone subscribe' => [null, null, true, null, true],
            'global channel alone unsubscribe' => [null, null, false, null, false],
        ];
        $cases = [];
        foreach ([false, true] as $loaded) {
            foreach ([false, true] as $optin) {
                foreach ($rules as $name => $rule) {
                    $cases[$name.($optin ? ' / opt-in' : ' / default').($loaded ? ' / loaded' : ' / cold')] = [$rule, $loaded, $optin];
                }
                $cases['no rule'.($optin ? ' / opt-in' : ' / default').($loaded ? ' / empty loaded' : ' / missing relation')] = [[null, null, null, null, !$optin], $loaded, $optin];
            }
        }

        return $cases;
    }

    #[DataProvider('precedenceCases')]
    public function test_scoped_global_and_default_precedence(array $rules, bool $loaded, bool $optin): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        foreach ([['mail', $scope], ['*', $scope], ['mail', null], ['*', null]] as $index => $target) {
            if ($rules[$index] !== null) {
                if ($rules[$index]) {
                    $user->subscribe(TestNotification::class, $target[0], $target[1]);
                } else {
                    $user->unsubscribe(TestNotification::class, $target[0], $target[1]);
                }
            }
        }
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        for ($repeat = 0; $repeat < 2; ++$repeat) {
            $this->assertSame($rules[4], $user->isSubscribed(TestNotification::class, 'mail', $scope, $optin ? ['mail'] : []));
            $exact = $user->findSubscription(TestNotification::class, 'mail', $scope);
            if ($rules[0] === null) {
                $this->assertNull($exact, 'findSubscription stays exact even when isSubscribed falls back.');
            } else {
                $this->assertSame($rules[0], $exact->isSubscribed());
            }
        }
        if ($loaded) {
            $this->assertCount(0, DB::getQueryLog(), 'All precedence branches must honor complete eager loading.');
        } else {
            $this->assertFalse($user->relationLoaded('notificationSubscriptions'));
        }
    }

    #[DataProvider('relationStates')]
    public function test_global_fallback_is_isolated_by_type_recipient_and_scope(bool $loaded): void
    {
        $user = User::query()->create();
        $otherUser = User::query()->create();
        $scope = User::query()->create();
        $otherScope = User::query()->create();
        $user->unsubscribe(TestNotification::class, '*', $otherScope);
        $user->unsubscribe('other type');
        $otherUser->unsubscribe(TestNotification::class);
        if ($loaded) {
            $user->load('notificationSubscriptions');
            $otherUser->load('notificationSubscriptions');
        }
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $scope, ['mail']));
        $this->assertFalse($user->isSubscribed('other type', 'mail', $scope));
        $this->assertFalse($otherUser->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $otherScope));
        $this->assertNull($user->findSubscription(TestNotification::class, '*', $scope));
        $this->assertNull($user->findSubscription(TestNotification::class));
    }

    public static function relationStates(): array
    {
        return ['cold' => [false], 'complete eager load' => [true]];
    }

    #[DataProvider('relationStates')]
    public function test_scoped_writes_and_reset_preserve_global_rows_and_return_to_fallback(bool $loaded): void
    {
        $user = User::query()->create();
        $scope = User::query()->create();
        $otherScope = User::query()->create();
        $global = $user->unsubscribe(TestNotification::class);
        $globalChannel = $user->unsubscribe(TestNotification::class, 'mail');
        $other = $user->subscribe(TestNotification::class, '*', $otherScope);
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }

        $scoped = $user->subscribe(TestNotification::class, '*', $scope);
        $this->assertNotSame($global->id, $scoped->id);
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $user->unsubscribe(TestNotification::class, 'mail', $scope);
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertFalse($global->refresh()->isSubscribed());
        $this->assertFalse($globalChannel->refresh()->isSubscribed());
        if ($loaded) {
            $user->load('notificationSubscriptions');
        }
        $this->assertSame($user, $user->resetSubscriptions(TestNotification::class, $scope));
        $this->assertNull($user->findSubscription(TestNotification::class, '*', $scope));
        $this->assertNull($user->findSubscription(TestNotification::class, 'mail', $scope));
        $this->assertFalse($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $this->assertSame($global->id, $user->findSubscription(TestNotification::class)->id);
        $this->assertSame($globalChannel->id, $user->findSubscription(TestNotification::class, 'mail')->id);
        $this->assertSame($other->id, $user->findSubscription(TestNotification::class, '*', $otherScope)->id);
        $this->assertSame(3, $user->notificationSubscriptions()->count());
        $user->resetSubscriptions(TestNotification::class, $scope)->subscribe(TestNotification::class, '*', $scope);
        $this->assertTrue($user->isSubscribed(TestNotification::class, 'mail', $scope));
        $user->resetSubscriptions(TestNotification::class);
        $this->assertSame($other->id, $user->findSubscription(TestNotification::class, '*', $otherScope)->id);
    }
}
