<?php

namespace Tests;

use App\Contracts\AiProviderInterface;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Support\DenyAiProvider;
use Tests\Support\DenyMetaCloudWhatsAppProvider;
use Tests\Support\DenyWppConnectWhatsAppProvider;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if ($this->configuredEnvironment() !== 'testing') {
            throw new LogicException('Tests may only run with APP_ENV=testing.');
        }

        $app = parent::createApplication();

        if (! $app->environment('testing')) {
            throw new LogicException('Tests may only run with APP_ENV=testing.');
        }

        $this->assertSafeDatabaseTarget($app);

        Http::preventStrayRequests();

        $app->bind(AiProviderInterface::class, DenyAiProvider::class);
        $app->forgetInstance(WhatsAppProviderRegistry::class);
        $app->singleton(
            WhatsAppProviderRegistry::class,
            fn ($app): WhatsAppProviderRegistry => new WhatsAppProviderRegistry([
                $app->make(DenyMetaCloudWhatsAppProvider::class),
                $app->make(DenyWppConnectWhatsAppProvider::class),
            ]),
        );

        return $app;
    }

    protected function setUp(): void
    {
        if ($this->configuredEnvironment() !== 'testing') {
            throw new LogicException('Tests may only run with APP_ENV=testing.');
        }

        parent::setUp();
    }

    protected function setUpTraits()
    {
        if ($this->configuredEnvironment() !== 'testing' || ! $this->app->environment('testing')) {
            throw new LogicException('Tests may only run with APP_ENV=testing.');
        }

        $this->assertSafeDatabaseTarget($this->app);

        return parent::setUpTraits();
    }

    protected function assertSafeDatabaseTarget($app): void
    {
        $config = $app->make('config');
        $connectionName = $config->get('database.default');
        if ($connectionName !== 'pgsql') {
            throw new LogicException('Test database guard rejected the default connection.');
        }

        $connection = $config->get('database.connections.pgsql');
        if (! is_array($connection)) {
            throw new LogicException('Test database guard rejected missing connection configuration.');
        }

        if (($connection['driver'] ?? null) !== 'pgsql') {
            throw new LogicException('Test database guard rejected the connection driver.');
        }

        if (($connection['host'] ?? null) !== '127.0.0.1') {
            throw new LogicException('Test database guard rejected the connection host.');
        }

        if ((string) ($connection['port'] ?? '') !== '5432') {
            throw new LogicException('Test database guard rejected the connection port.');
        }

        if (($connection['database'] ?? null) !== 'wabiz_test') {
            throw new LogicException('Test database guard rejected the database name.');
        }

        if (array_key_exists('url', $connection) && $connection['url'] !== null && $connection['url'] !== '') {
            throw new LogicException('Test database guard rejected a URL-based connection override.');
        }

        if (! empty($connection['read']) || ! empty($connection['write'])) {
            throw new LogicException('Test database guard rejected a read/write connection override.');
        }
    }

    private function configuredEnvironment(): ?string
    {
        $processEnvironment = getenv('APP_ENV');
        if (! is_string($processEnvironment) || $processEnvironment !== 'testing') {
            return null;
        }

        foreach ([$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null] as $environment) {
            if ($environment !== null && $environment !== 'testing') {
                return null;
            }
        }

        return $processEnvironment;
    }
}

