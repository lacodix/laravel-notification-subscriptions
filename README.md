# Laravel Notification Subscriptions

Manage per-recipient notification preferences in Laravel: subscribe or unsubscribe
by channel, require opt-in, and scope preferences to another Eloquent model. The
package filters Laravel's normal notification sending flow; it does not provide a
settings UI.

[![Latest Stable Version](https://poser.pugx.org/lacodix/laravel-notification-subscriptions/v/stable)](https://packagist.org/packages/lacodix/laravel-notification-subscriptions) [![Total Downloads](https://poser.pugx.org/lacodix/laravel-notification-subscriptions/downloads)](https://packagist.org/packages/lacodix/laravel-notification-subscriptions) [![License](https://poser.pugx.org/lacodix/laravel-notification-subscriptions/license)](https://packagist.org/packages/lacodix/laravel-notification-subscriptions)

A fork of [liran-co/laravel-notification-subscriptions](https://github.com/liran-co/laravel-notification-subscriptions),
originally authored by Liran Cohen and maintained by [Lacodix](https://github.com/lacodix).
Original author and MIT attribution are retained. The package name is
`lacodix/laravel-notification-subscriptions`, and its public namespace is
`Lacodix\LaravelNotificationSubscriptions`.

## Requirements and installation

Requires PHP `^8.2` (>=8.2 and <9) and Laravel `^11.0|^12.0|^13.0`.
Laravel 13 requires PHP >=8.3. Laravel 11 compatibility does not imply current
security support; use a maintained Laravel version for production. Older
environments need a compatible prior package version.

```bash
composer require lacodix/laravel-notification-subscriptions
php artisan migrate
```

Laravel auto-discovers the package provider. The migration creates the
`notification_subscriptions` table. To optionally publish
`config/notification-subscriptions.php`, select the provider explicitly:

```bash
php artisan vendor:publish --provider='Lacodix\LaravelNotificationSubscriptions\NotificationSubscriptionsServiceProvider'
```

## Enable preferences on your user model

Add both Laravel's `Notifiable` and the package's `HasNotificationSubscriptions`
traits to your Eloquent notifiable model. Keep your existing model configuration;
this is a minimal example:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lacodix\LaravelNotificationSubscriptions\Traits\HasNotificationSubscriptions;

class User extends Authenticatable
{
    use Notifiable;
    use HasNotificationSubscriptions;
}
```

Notifiables without `HasNotificationSubscriptions` are not filtered by this
package. Configure your delivery channels using Laravel's
[notification documentation](https://laravel.com/docs/13.x/notifications).

## Subscribe, unsubscribe and reset

Pass the notification's exact class name. Examples below use your application's
`App\Notifications\InvoicePaid`; a complete illustrative implementation appears in
[Model scoping](#model-scoping). Lookup IDs are examples for existing application
records.

```php
use App\Models\User;
use App\Notifications\InvoicePaid;

$user = User::findOrFail(1);

$user->unsubscribe(InvoicePaid::class);         // Global wildcard: all channels.
$user->subscribe(InvoicePaid::class, 'mail');   // Explicit mail exception.
$user->unsubscribe(InvoicePaid::class, 'mail'); // Change only the mail rule.
$user->subscribe(InvoicePaid::class);           // Change the wildcard rule.
```

An exact channel rule takes priority over `'*'`. Changing a wildcard does not
remove channel-specific rules: in this example, the final wildcard subscription
still leaves mail unsubscribed. Subscribe/unsubscribe reuse an existing exact
rule instead of creating a new row each time.

Reset removes the requested notification's rules in the selected scope and returns
the notifiable, so calls can be chained:

```php
use App\Notifications\InvoicePaid;

$user->resetSubscriptions(InvoicePaid::class);
$user->resetSubscriptions(InvoicePaid::class)->subscribe(InvoicePaid::class);
```

Without a model argument, reset removes only global rules; scoped rules remain.
Without a matching preference, delivery is allowed unless the channel is opt-in.

## Model scoping

A scope lets a recipient keep different preferences for individual organizations,
invoices or other Eloquent models. The following illustrative notification uses
your application's persisted `App\Models\Organization` model. Replace the message
and channel implementation with your application's requirements:

```php
<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoicePaid extends Notification
{
    public function __construct(
        public Organization $organization,
        public array $optIn = [],
        public bool $ignoreSubscriptions = false,
    ) {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Invoice paid')
            ->line('An invoice for your organization has been paid.');
    }

    public function getSubscriptionModel($notifiable): ?Model
    {
        return $this->organization;
    }

    public function getOptInSubscriptions(): array
    {
        return $this->optIn;
    }
}
```

`getSubscriptionModel($notifiable)` returns the Eloquent model to use as a scope,
or `null` for global preferences. Rules store the concrete `get_class($model)`
and its `id` property (`optional($model)->id`), not a morph alias or custom primary
key returned by `getKey()`.

```php
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvoicePaid;

$user = User::findOrFail(1);
$organization = Organization::findOrFail(1);

$user->unsubscribe(InvoicePaid::class, '*', $organization);
$user->subscribe(InvoicePaid::class, 'mail', $organization);
$user->notify(new InvoicePaid($organization)); // Mail is allowed by the scoped exception.

$user->resetSubscriptions(InvoicePaid::class, $organization);
```

The scoped reset removes only that model's rules. Global rules and other scopes
remain; global preferences become the fallback for this scope.

## Opt-in channels

Laravel's normal `notify()` and `Notification::send()` flow reads
`getOptInSubscriptions()` with no arguments. It must return the channels that need
an explicit matching subscription. In the illustrative notification above, the
constructor's `optIn` array supplies that list:

```php
use App\Notifications\InvoicePaid;

$notification = new InvoicePaid($organization, optIn: ['mail']);
$user->resetSubscriptions(InvoicePaid::class, $organization);
$user->resetSubscriptions(InvoicePaid::class);
$user->notify($notification); // Suppressed: no mail or wildcard subscription exists.

$user->subscribe(InvoicePaid::class, 'mail', $organization);
$user->notify($notification); // Allowed by the scoped mail subscription.
```

A matching wildcard or global subscription can also satisfy opt-in according to
the precedence below. Direct `isSubscribed()` checks require you to pass the opt-in
list explicitly; they do not construct a notification or call its methods.

## Preference precedence

For each recipient, notification class and channel, the first matching rule wins:

1. Scoped exact channel.
2. Scoped wildcard (`'*'`).
3. Global exact channel.
4. Global wildcard (`'*'`).
5. No rule: allow, unless the channel is opt-in.

Unscoped notifications start at the global rules. A global unsubscribe suppresses
scoped notifications when no scoped rule exists; an explicit scoped subscription
can override it. A global subscription can satisfy an opt-in channel. Rules for
other recipients, notification types or model scopes are never used as fallback.

Use exact stored notification, channel and scope-class names matching the
notification class, `via()` channel values and `get_class($model)`. Hand-written
values that depend on database collation (such as different case or trailing
spaces) are not guaranteed equivalent between SQL and loaded-collection matching.

## Read preferences and eager-load

```php
use App\Notifications\InvoicePaid;

// Resolved decision, including wildcard/global fallback and an explicit opt-in list.
$allowed = $user->isSubscribed(InvoicePaid::class, 'mail', $organization, ['mail']);

// Exact tuple only: returns a NotificationSubscription or null, with no fallback.
$rule = $user->findSubscription(InvoicePaid::class, 'mail', $organization);

// Query all preferences, or just this scope, using the Eloquent relation.
$allRules = $user->notificationSubscriptions()->get();
$scopedRules = $user->notificationSubscriptions()->model($organization)->get();
```

For multiple recipients, eager-load the **complete** relation:

```php
use App\Models\User;
use App\Notifications\InvoicePaid;

$users = User::with('notificationSubscriptions')->get();

foreach ($users as $user) {
    $allowed = $user->isSubscribed(InvoicePaid::class, 'mail');
}
```

Loaded `findSubscription()` and `isSubscribed()` reads perform no additional SQL,
including misses and empty collections. Unloaded reads query the requested rules
without loading the whole relation.

- Load all rows and columns for the current notifiable. Filtered/partially selected
  collections are not a complete preference set. Reload or unset copied/reattached
  relations after `replicate()` or mixed-model-class collection loads.
- `subscribe()`, `unsubscribe()`, `createSubscription()` and `resetSubscriptions()`
  invalidate the relation on the instance they modify. Subsequent package reads
  query current preferences. With strict lazy-loading prevention, call
  `$user->load('notificationSubscriptions')` before accessing the relation property.
- Other instances keep their cached collections. After external writes, including
  direct subscription-record changes, reload with
  `$user->load('notificationSubscriptions')` before reading preferences.

Prefer subscribe/unsubscribe for preference changes. `createSubscription()` is a
direct row creation API, not an upsert.

## Bypass filtering and observe suppression

The default excluded channel is `database`. Change
`config/notification-subscriptions.php` if needed:

```php
<?php

return [
    'excluded_channels' => ['database'],
];
```

Or bypass subscription checks for a notification using its declared public
`ignoreSubscriptions` property. The example class above exposes it as a constructor
argument:

```php
use App\Notifications\InvoicePaid;

$user->notify(new InvoicePaid($organization, ignoreSubscriptions: true));
```

Excluded channels and `ignoreSubscriptions` skip only this package's filtering.
Other application [event listeners](https://laravel.com/docs/13.x/events) can still
veto delivery. `isSubscribed()` itself checks preferences; these bypasses belong
to the sending listener.

When this package vetoes a channel, it emits `NotificationSuppressed`. Register a
listener in your application's service provider `boot()` method, for example:

```php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lacodix\LaravelNotificationSubscriptions\Events\NotificationSuppressed;

Event::listen(NotificationSuppressed::class, function (NotificationSuppressed $event): void {
    Log::info('Notification suppressed', [
        'notification' => get_class($event->notification),
        'channel' => $event->channel,
    ]);
});
```

The event exposes `notifiable`, `notification`, `channel` and the original Laravel
`NotificationSending` event as `event`. Application-listener vetoes alone do not
emit this package event.

## Upgrading

When migrating from upstream, remove `liran-co/laravel-notification-subscriptions`
and install `lacodix/laravel-notification-subscriptions`. Replace imports and manual
provider references from `LiranCo\NotificationSubscriptions` to
`Lacodix\LaravelNotificationSubscriptions`. There are no compatibility aliases or
Composer `replace` declaration for upstream; stored rows, schema and configuration
stay unchanged. Clear or rebuild Laravel's event cache after changing provider
references:

```bash
php artisan event:clear
# Or rebuild for your deployment:
php artisan event:cache
```

When updating an existing Lacodix installation, check the current PHP/Laravel
requirements above. Global preferences now act as fallback for scoped notifications
in both directions: existing unsubscribes may suppress delivery, and subscriptions
may satisfy opt-in. Add an explicit scoped rule to keep a model-specific exception.
No schema or data migration is required for this preference-resolution change.

## Contributing

From a checkout with the required PHP extensions and Composer:

```bash
composer update --prefer-stable
composer test
composer quality
composer format
node --test .github/scripts/*.test.cjs
```

Tests use Testbench, in-memory SQLite, the package provider/migrations and Laravel's
real notification sender with a local recording channel. Quality runs PHP CS Fixer
and Larastan. No development lock file is committed.

The 22-lane compatibility matrix covers:

| Laravel | Testbench | PHP | Dependency selection |
| --- | --- | --- | --- |
| 11 | 9 | 8.2, 8.3, 8.4, 8.5 | prefer-stable and prefer-lowest |
| 12 | 10 | 8.2, 8.3, 8.4, 8.5 | prefer-stable and prefer-lowest |
| 13 | 11 | 8.3, 8.4, 8.5 | prefer-stable and prefer-lowest |

Lowest means the lowest resolvable stable dependencies under the development
constraints and Composer's security policy, not necessarily a framework's initial
`.0` version. Laravel 11 tests have explicit advisory exceptions in the isolated
[CI lane helper](.github/scripts/prepare-test-lane.php); they do not weaken the
package manifest or imply Laravel 11 security support.

Useful source and test entry points:

| Area | Source |
| --- | --- |
| Preference reads/writes and cache handling | [HasNotificationSubscriptions](src/Traits/HasNotificationSubscriptions.php) |
| Stored rules and exact model scope | [NotificationSubscription](src/Models/NotificationSubscription.php) |
| Sending veto/bypass and suppression fields | [Listener](src/Listeners/NotificationSendingListener.php), [event](src/Events/NotificationSuppressed.php) |
| Config, migration and listener registration | [Provider](src/NotificationSubscriptionsServiceProvider.php), [config](config/notification-subscriptions.php), [migrations](database/migrations) |
| Precedence, isolation, mutations and real sending | [Feature tests](tests/Feature), [fixtures](tests/Fixtures) |

Preserve exact-scope writes, recipient/type isolation, channel/scoped/global
precedence, zero-query complete loaded reads and fresh mutation lookup. Sending
suppression returns `false`; allowed/bypassed notifications abstain (`null`) so other
application listeners can run and veto. Cover behavior changes through the real
sender as well as direct preference checks.

## License

[MIT](LICENSE).
