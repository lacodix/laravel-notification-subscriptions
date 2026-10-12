# Laravel Notification Subscriptions

Laravel Notification Subscriptions is a package that hooks directly into Laravel's existing [notification system](https://laravel.com/docs/master/notifications) and adds functionality to manage user subscriptions to your app's notifications and suppress them automatically when they shouldn't be sent. You can subscribe and unsubscribe users to specific notification channels, create opt-in notifications, and scope your subscriptions by another model.

[![Latest Stable Version](https://poser.pugx.org/lacodix/laravel-notification-subscriptions/v/stable)](https://packagist.org/packages/lacodix/laravel-notification-subscriptions) [![Total Downloads](https://poser.pugx.org/lacodix/laravel-notification-subscriptions/downloads)](https://packagist.org/packages/lacodix/laravel-notification-subscriptions) [![License](https://poser.pugx.org/lacodix/laravel-notification-subscriptions/license)](https://packagist.org/packages/lacodix/laravel-notification-subscriptions)

Maintained by [Lacodix](https://github.com/lacodix) from
[Liran Cohen's original package](https://github.com/liran-co/laravel-notification-subscriptions).
The public namespace is now `Lacodix\LaravelNotificationSubscriptions`. This is a
breaking change from upstream; applications must update their imports and provider
references. Original author and MIT attribution are retained.
This fork has its own Composer identity; it does not declare that it replaces all
upstream package versions.

## Installation

To get started, install the `lacodix/laravel-notification-subscriptions` package:

```bash
composer require lacodix/laravel-notification-subscriptions
```

This command is available after the owner registers the fork on Packagist and
publishes its first release. For an application migrating from upstream, remove
the old `liran-co/laravel-notification-subscriptions` dependency before adding the
Lacodix package and update its namespace references as described below. Laravel
discovers `Lacodix\LaravelNotificationSubscriptions\NotificationSubscriptionsServiceProvider`
automatically.

Run the migration to create the `notification_subscriptions` table:
```bash
php artisan migrate
```

Optionally publish the configuration file by running and selecting the appropriate provider option:
```bash
php artisan vendor:publish
```

### Upgrading from upstream

Replace every `LiranCo\NotificationSubscriptions` import or fully qualified class
reference with `Lacodix\LaravelNotificationSubscriptions`, including the
`HasNotificationSubscriptions` trait, subscription model, events, listeners and any
manually registered service providers. Class basenames and method signatures stay
the same; there are no compatibility aliases or legacy autoload roots.

After updating dependencies and imports, clear or rebuild Laravel's event cache
during deployment with `php artisan event:clear` or `php artisan event:cache`,
since a stale provider-keyed events cache can leave subscription filtering
unregistered.

There is no schema or migration change for this namespace rename. Existing
subscription rows and configuration keys remain unchanged. Application code must
be updated by its owner; this package setup does not modify consumers.

## Basic usage

This package uses a [Listener](https://laravel.com/docs/master/events) to listen for any notifications that get sent in your application. When a notification gets triggered, the package checks to see if the notification should actually be sent according to the user's subscriptions. If not, the notification is suppressed.

### Getting started

This package assumes you've already setup Laravel's notification system. If you haven't [read the docs](https://laravel.com/docs/master/notifications) to get started.

Add the `HasNotificationSubscriptions ` trait to your `User` model:

```php
use Illuminate\Database\Eloquent\Model;
use Lacodix\LaravelNotificationSubscriptions\Traits\HasNotificationSubscriptions;

class User extends Model
{
    use HasNotificationSubscriptions;

    // ...
}
```

### Unsubscribing

To unsubscribe a user from a specific `Notification`, pass the class name of that notification to the `unsubscribe` function.

```php
use App\Notifications\InvoicePaid;

$user->unsubscribe(InvoicePaid::class); //You can also pass a string, but this is the preferred method.
```

The above will unsubscribe the user from all channels. You can unsubscribe a user from a specific channel by passing the channel name as the second parameter:

```php
use App\Notifications\InvoicePaid;

$user->unsubscribe(InvoicePaid::class, 'mail');
```

Now, whenever an `InvoicePaid` notification is sent, the package will automatically detect that the user has unsubscribed and suppress the notification. For example:

```php
use App\Notifications\InvoicePaid;

$user->notify(new InvoicePaid($invoice)); //This won't get sent.
```

### Opt-in notifications

By default, all notifications will be sent if no subscribe/unsubscribe record is found. This means you don't need to explicitly **subscribe** a user to a notification, you only need to **unsubscribe** them.

In some cases, however, you'd like to create opt-in notifications. To do so, modify your notification class and add a function called `getOptInSubscriptions`:

```php
<?php

namespace App\Notifications;

// ...

class InvoicePaid extends Notification
{
    public function via($notifiable)
    {
        return ['mail', 'sms'];
    }

    public function getOptInSubscriptions()
    {
        return ['sms'];
    }
}
```

The package will now **always** suppress the `sms` channel unless the user is explicitly subscribed to it.

### Subscribing

To subscribe a user to an opt-in notification or resubscribe them to a previously unsubscribed notification:

```php
use App\Notifications\InvoicePaid;

$user->subscribe(InvoicePaid::class);
```

Similarly, you can apply a channel:

```php
use App\Notifications\InvoicePaid;

$user->subscribe(InvoicePaid::class, 'mail');
```

### Resetting subscriptions

This package makes no assumptions about how your application manages notifications and subscriptions. For example, if you unsubscribe a user from a particular notification channel, and later subscribe them to all channels, the previous record won't be deleted. To reset the notifications on a user:

```php
use App\Notifications\InvoicePaid;

$user->resetSubscriptions(InvoicePaid::class);
```

You can chain the `resetSubscriptions`:

```php
use App\Notifications\InvoicePaid;

$user->resetSubscriptions(InvoicePaid::class)->subscribe(InvoicePaid::class);
```

### Retrieving subscriptions

You can get a user's subscriptions by using the `notificationSubscriptions()` relation:

```php
$user->notificationSubscriptions();
```

## Model scoping

In some applications, you need to unsubscribe users from notifications related to a certain model. For example, if a user is a part of multiple organizations, they may only want to unsubscribe from a single organization. You can accomplish this by applying a model scope to your notifications:

```php
use App\Models\Organization;
use App\Notifications\InvoicePaid;

//...

$organization = Organization::find(1);

$user->unsubscribe(InvoicePaid::class, '*', $organization);
```

Or, for a single channel:

```php
use App\Models\Organization;
use App\Notifications\InvoicePaid;

//...

$organization = Organization::find(1);

$user->unsubscribe(InvoicePaid::class, 'mail', $organization);
```

Next, we need a way to retrieve the `Organization` when your notification is sent. Add a function called `getSubscriptionModel` to your notification class to tell it how to retrieve the model:

```php
<?php

namespace App\Notifications;

// ...

class InvoicePaid extends Notification
{
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function getSubscriptionModel($notifiable)
    {
        return $this->invoice->organization;
    }
}
```

Now, when this notification gets sent, it will check for the model scope and apply it if necessary. You can add your own logic to `getSubscriptionModel` and even return `null` in cases you don't want to scope the subscription.

### Resetting scoped subscriptions

To reset the notifications on a scoped subscription:

```php
use App\Models\Organization;
use App\Notifications\InvoicePaid;

//...

$organization = Organization::find(1);

$user->resetSubscriptions(InvoicePaid::class, $organization);
```

### Retrieving scoped subscriptions

Retrieve subscriptions related to a certain model:

```php
use App\Models\Organization;

//...

$organization = Organization::find(1);

$user->notificationSubscriptions()->model($organization);
```

## Advanced usage

### Ignoring subscriptions

If you'd like the package to ignore your notification entirely, and skip any suppressions, set the public `$ignoreSubscriptions` property to true in your notification class:

```php
<?php

namespace App\Notifications;

// ...

class InvoicePaid extends Notification
{
    public function __construct(Invoice $invoice, $ignore = false)
    {
        $this->ignoreSubscriptions = $ignore;
    }
}
```

```php
use App\Notifications\InvoicePaid;

$user->notify(new InvoicePaid($invoice, true)); //This bypasses subscription checks; other application listeners may still veto.
```

### Excluding channels

You may want to exclude certain channels from being considered when checking for unsubscribes. By default, we already exclude the `database` channel. You can configure this in the configuration file:

```php
<?php

return [
	
	'excluded_channels' => ['database'],

];
```

## Resolution logic

The package uses the following logic to resolve whether or not to send a notification:

1. If `channel` is in `excluded_channels`, send the notification.
2. If the notification has the public property `$ignoreSubscriptions` set to `true`, send the notification.
3. Attempt to retrieve a record for the particular channel, if none is found, attempt to retrieve a record for all channels (i.e. `"*"`).

   3a. If there is no record, and the channel is not opt-in, send the notification.

   3b. If there is a record, send the notification based on the status of the subscription (subscribed or unsubscribed).

## Development and compatibility

The runtime manifest retains PHP `^7.2.5|^8.0|^8.1` and Laravel 6–11, and adds
Laravel 12 and 13. These historical runtime constraints are retained for existing
applications; the modern development harness requires PHP 8.2 or newer and tests
the following matrix:

| Laravel | Testbench | PHP | Dependency selection |
| --- | --- | --- | --- |
| 11 | 9 | 8.2, 8.3, 8.4, 8.5 | prefer-stable and prefer-lowest |
| 12 | 10 | 8.2, 8.3, 8.4, 8.5 | prefer-stable and prefer-lowest |
| 13 | 11 | 8.3, 8.4, 8.5 | prefer-stable and prefer-lowest |

Framework PHP requirements still apply (Laravel 11/12 require PHP 8.2; Laravel 13
requires PHP 8.3). Historical Laravel 6–10 / PHP 7 lanes are not tested by this CI.
Lowest lanes select the lowest **resolvable** dependencies under the development
constraints and Composer's security policy; they do not guarantee each framework's
initial `.0` release. All lanes require stable dependency releases and retain
Composer audits and platform checks.

Laravel 11 has ended security support. Its isolated CI manifests contain four
explicit advisory-ID exceptions for debug-page XSS, signed-URL path confusion and
email-validation CRLF injection (including a duplicate advisory record). Those
features are not used by these notification tests. The exceptions are documented
in `.github/scripts/prepare-test-lane.php`; they do not apply to the distributed
manifest, Laravel 12/13 or other dependencies. New advisories still fail CI.
Passing a Laravel 11 compatibility lane is not a claim that Laravel 11 is secure;
use a maintained Laravel version for production.

```bash
composer update --prefer-stable
composer test
composer quality
composer format
node --test .github/scripts/*.test.cjs
```

Tests use Testbench, in-memory SQLite, the package provider/migrations, and Laravel's
real notification sender/event dispatcher. Subscription vetoes return `false`;
allowed notifications return `null` so later application listeners can run and veto.
Quality uses PHP CS Fixer with conservative PSR-2 rules and Larastan at level 4.
No development lock file is committed for this library.

## Publishing

The `Run Unit Tests` workflow runs tests and quality checks with read-only permissions
for pull requests and pushes to `master` or `ci/**`.
For a push to this repository's `master`, release runs only after every test lane
and the reusable quality workflow succeed for that exact commit. Release concurrency
is serialized. A run skips publication if master has since moved.

Version planning uses pinned `rymndhng/release-on-push-action` in dry-run mode with
the Lacodix default **minor** bump after the first release. Associated PR labels
`release:major`, `release:minor` and `release:patch` override that default; use only
one bump label.
The PR label `norelease` or `[norelease]` in the pushed commit title skips publishing,
including the first release. GitHub supplies release notes and `GITHUB_TOKEN` is
sufficient; only the release job requests `contents: write` and `pull-requests: read`.

Back-to-back merges can skip older release runs; only the newest commit and its
associated PR labels choose the next version. Wait for each release, or put the
highest required bump label (especially `release:major` for breaking changes) on
the final PR allowed to publish, and do not mark that PR `norelease`.

The fork inherits tags through `v1.8` but initially has no GitHub releases. The action
normally starts at `0.0.0` in that case, ignoring tags. The small release helper lifts
every recognized first-release minor, patch or major plan to a **major** above the
highest inherited stable tag (two-component tags are normalized with patch zero).
The breaking public namespace rename requires **`v2.0.0`** with current inherited
tags, even with a patch or minor label. Unknown plans fail closed. Historical tags
are preserved, and `norelease` still skips the first publication. Once a stable
GitHub release exists, the action's normal plan applies with the default minor bump.

The helper refuses tag collisions or a version at/below inherited stable tags,
checks the target tag before publication, atomically creates it at the CI-validated
SHA, and creates a stable GitHub release with generated notes. Unexpected bootstrap
plans, missing baseline tags, or API errors fail closed. If tag creation succeeds
but release creation fails, the owner must verify that exact tag's SHA and create
the missing GitHub release for it before retrying; automation never deletes or
retargets tags. Manual recovery must preserve existing tags and verify their SHAs;
never attach a release to a different commit or retarget a tag.

Owner setup still required:

1. Approve and merge these changes; enable GitHub Actions and allow the narrowly
   scoped release token permissions. Configure branch protection to require the
   test matrix and quality checks.
2. Register `lacodix/laravel-notification-subscriptions` on Packagist against this
   GitHub repository, and configure its GitHub update integration.
3. Authorize the first master release and verify the resulting tag/release SHA,
   generated notes and Packagist synchronization. Before authorizing it, use
   `norelease` on the setup PR if publication should wait.

Local setup does not publish, register Packagist, change GitHub settings or validate
remote workflow execution. Release automation activates only after an owner-approved
merge to master; tests and quality checks also run on pull requests and `ci/**` pushes.

## License
Released under the [MIT](https://choosealicense.com/licenses/mit/) license. See [LICENSE](LICENSE) for more information.
