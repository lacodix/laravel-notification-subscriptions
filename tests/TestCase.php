<?php

namespace Lacodix\LaravelNotificationSubscriptions\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Schema;
use Lacodix\LaravelNotificationSubscriptions\NotificationSubscriptionsServiceProvider;
use Lacodix\LaravelNotificationSubscriptions\Tests\Fixtures\RecordingChannel;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [NotificationSubscriptionsServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise the real migration path registered by the package provider.
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });

        $this->app->singleton(RecordingChannel::class);
        $this->app->make(ChannelManager::class)->extend('recording', function ($app) {
            return $app->make(RecordingChannel::class);
        });
    }
}
