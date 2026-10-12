<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests\Feature;

use Illuminate\Notifications\Events\NotificationSending;
use Lacodix\LaravelNotificationSubscriptions\Providers\EventServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PublicNamespaceTest extends TestCase
{
    public static function publicTypes(): array
    {
        return [
            'package provider' => ['Lacodix\LaravelNotificationSubscriptions\NotificationSubscriptionsServiceProvider', false],
            'event provider' => ['Lacodix\LaravelNotificationSubscriptions\Providers\EventServiceProvider', false],
            'listener' => ['Lacodix\LaravelNotificationSubscriptions\Listeners\NotificationSendingListener', false],
            'suppression event' => ['Lacodix\LaravelNotificationSubscriptions\Events\NotificationSuppressed', false],
            'subscription model' => ['Lacodix\LaravelNotificationSubscriptions\Models\NotificationSubscription', false],
            'subscription trait' => ['Lacodix\LaravelNotificationSubscriptions\Traits\HasNotificationSubscriptions', true],
        ];
    }

    #[DataProvider('publicTypes')]
    public function test_public_types_autoload_under_the_new_namespace(string $type, bool $trait): void
    {
        $this->assertTrue($trait ? trait_exists($type) : class_exists($type), $type.' must autoload.');
        $this->assertSame($type, (new ReflectionClass($type))->getName(), 'Public types must be real declarations, not aliases.');
    }

    public function test_manifest_exposes_only_the_new_package_root_and_discovery_provider(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Lacodix\LaravelNotificationSubscriptions\\' => 'src'], $manifest['autoload']['psr-4']);
        $this->assertSame(['Lacodix\LaravelNotificationSubscriptions\Tests\\' => 'tests/'], $manifest['autoload-dev']['psr-4']);
        $this->assertSame([
            'Lacodix\LaravelNotificationSubscriptions\NotificationSubscriptionsServiceProvider',
        ], $manifest['extra']['laravel']['providers']);
    }

    public function test_generated_autoloader_has_only_the_new_root_for_package_source(): void
    {
        $loader = require __DIR__.'/../../vendor/autoload.php';
        $source = realpath(__DIR__.'/../../src');
        $roots = array_filter($loader->getPrefixesPsr4(), function (array $paths) use ($source): bool {
            return in_array($source, array_map('realpath', $paths), true);
        });

        $this->assertSame(['Lacodix\LaravelNotificationSubscriptions\\'], array_keys($roots));
    }

    public function test_event_provider_registers_the_renamed_listener(): void
    {
        $this->assertTrue(class_exists(EventServiceProvider::class), 'The renamed event provider must autoload.');
        $properties = (new ReflectionClass(EventServiceProvider::class))->getDefaultProperties();
        $this->assertSame([
            NotificationSending::class => [
                'Lacodix\LaravelNotificationSubscriptions\Listeners\NotificationSendingListener',
            ],
        ], $properties['listen']);
    }
}
