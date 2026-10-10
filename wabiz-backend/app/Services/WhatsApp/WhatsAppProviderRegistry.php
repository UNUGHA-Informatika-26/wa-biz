<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\WhatsAppConnection;

class WhatsAppProviderRegistry
{
    /** @var array<string, WhatsAppProviderInterface> */
    private array $providers = [];

    /** @param iterable<WhatsAppProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->identifier()] = $provider;
        }
    }

    public function forConnection(WhatsAppConnection $connection, int $organizationId): WhatsAppProviderInterface
    {
        if (! $connection->exists || ! $connection->organization_id
            || (int) $connection->organization_id !== $organizationId) {
            throw new WhatsAppProviderException('invalid_connection');
        }

        $provider = $this->providers[$connection->provider] ?? null;
        if (! $provider) {
            throw new WhatsAppProviderException('unsupported_provider');
        }

        return $provider;
    }
}
