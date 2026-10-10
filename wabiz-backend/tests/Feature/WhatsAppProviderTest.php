<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\MetaCloudWhatsAppProvider;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppProviderTest extends TestCase
{
    use DatabaseTransactions;

    private string $accessToken = 'fake-meta-token-never-use-for-network';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.meta.base_url' => 'https://graph.facebook.com',
            'services.whatsapp.meta.graph_api_version' => 'v23.0',
            'services.whatsapp.meta.connect_timeout' => 3,
            'services.whatsapp.meta.timeout' => 8,
        ]);
    }

    public function test_meta_provider_implements_generic_contract_and_sends_configured_cloud_api_request(): void
    {
        Http::fake(['*' => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '6281234567890', 'wa_id' => '6281234567890']],
            'messages' => [['id' => 'wamid.test-id']],
        ], 200)]);

        $provider = new MetaCloudWhatsAppProvider;
        $connection = $this->connection(['phone_number_id' => 'configured-phone-id']);
        $this->assertInstanceOf(WhatsAppProviderInterface::class, $provider);
        $result = $provider->sendText($connection, new WhatsAppSendTextRequest('+62 812-3456-7890', 'Halo'));

        $this->assertTrue($result->success);
        $this->assertSame('meta_cloud', $result->provider);
        $this->assertSame('wamid.test-id', $result->externalMessageId);
        $this->assertSame('accepted', $result->status);
        $this->assertSame([], $result->metadata);
        $this->assertStringNotContainsString($this->accessToken, json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://graph.facebook.com/v23.0/configured-phone-id/messages'
            && $request->hasHeader('Authorization', 'Bearer '.$this->accessToken)
            && $request['messaging_product'] === 'whatsapp'
            && $request['recipient_type'] === 'individual'
            && $request['to'] === '6281234567890'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Halo'
            && $request['text']['preview_url'] === false
        );
        Http::assertSentCount(1);
    }

    public function test_provider_uses_encrypted_connection_credentials_and_not_a_hardcoded_phone_id(): void
    {
        $connection = $this->connection(['phone_number_id' => 'another-phone-id']);
        $this->assertNotSame($this->accessToken, $connection->getRawOriginal('credentials'));
        $this->assertSame($this->accessToken, $connection->credentials['access_token']);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.second']]], 200)]);
        (new MetaCloudWhatsAppProvider)->sendText($connection, new WhatsAppSendTextRequest('6281234567890', 'hello'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/another-phone-id/messages'));
    }

    public function test_meta_access_token_is_not_returned_by_connection_api(): void
    {
        $connection = $this->connection();
        $user = User::forceCreate([
            'organization_id' => $connection->organization_id,
            'name' => 'Meta connection admin', 'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => 'password', 'role' => 'admin',
        ]);

        $response = $this->withToken($user->createToken('meta-provider-test')->plainTextToken)
            ->getJson('/api/whatsapp/connections/'.$connection->id)
            ->assertOk()
            ->assertJsonMissingPath('data.credentials');

        $this->assertStringNotContainsString($this->accessToken, $response->getContent());
    }

    public function test_meta_access_token_can_be_saved_through_existing_connection_api_encrypted(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Meta API test', 'slug' => 'meta-api-'.bin2hex(random_bytes(6)),
        ]);
        $user = User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Meta API admin', 'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => 'password', 'role' => 'admin',
        ]);

        $response = $this->withToken($user->createToken('meta-credential-test')->plainTextToken)
            ->postJson('/api/whatsapp/connections', [
                'provider' => 'meta_cloud', 'display_name' => 'Meta connection',
                'phone_number' => '+6281234567890', 'phone_number_id' => 'meta-phone-'.bin2hex(random_bytes(4)),
                'business_account_id' => 'meta-business', 'credentials' => ['access_token' => $this->accessToken],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('data.credentials');

        $connection = WhatsAppConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame($this->accessToken, $connection->credentials['access_token']);
        $this->assertNotSame($this->accessToken, $connection->getRawOriginal('credentials'));
        $this->assertStringNotContainsString($this->accessToken, $response->getContent());
    }

    public function test_provider_registry_resolves_meta_and_rejects_unsupported_or_cross_tenant_connections(): void
    {
        $registry = app(WhatsAppProviderRegistry::class);
        $connection = $this->connection();
        $this->assertInstanceOf(MetaCloudWhatsAppProvider::class, $registry->forConnection($connection, (int) $connection->organization_id));

        $connection->forceFill(['provider' => 'unsupported_cloud'])->save();
        try {
            $registry->forConnection($connection, (int) $connection->organization_id);
            $this->fail('Unsupported provider must be rejected.');
        } catch (WhatsAppProviderException $exception) {
            $this->assertSame('unsupported_provider', $exception->failureKind);
        }

        $connection->forceFill(['provider' => 'meta_cloud'])->save();
        try {
            $registry->forConnection($connection, (int) $connection->organization_id + 1);
            $this->fail('A connection from another organization must be rejected.');
        } catch (WhatsAppProviderException $exception) {
            $this->assertSame('invalid_connection', $exception->failureKind);
        }
    }

    public function test_missing_credentials_invalid_phone_and_missing_version_are_controlled_and_send_nothing(): void
    {
        Http::preventStrayRequests();
        $provider = new MetaCloudWhatsAppProvider;
        $connection = $this->connection(['credentials' => ['webhook_secret' => 'not-meta-token']]);
        foreach ([
            [$connection, 'missing_credentials'],
            [$this->connection(['phone_number_id' => '']), 'invalid_phone_number_id'],
        ] as [$invalidConnection, $expectedKind]) {
            try {
                $provider->sendText($invalidConnection, new WhatsAppSendTextRequest('6281234567890', 'hello'));
                $this->fail('Invalid provider configuration must be rejected.');
            } catch (WhatsAppProviderException $exception) {
                $this->assertSame($expectedKind, $exception->failureKind);
                $this->assertStringNotContainsString($this->accessToken, $exception->getMessage());
            }
        }

        config(['services.whatsapp.meta.graph_api_version' => '']);
        try {
            $provider->sendText($this->connection(), new WhatsAppSendTextRequest('6281234567890', 'hello'));
            $this->fail('Missing API version must be rejected.');
        } catch (WhatsAppProviderException $exception) {
            $this->assertSame('configuration_missing_api_version', $exception->failureKind);
        }
        Http::assertNothingSent();
    }

    public function test_provider_maps_http_failures_without_exposing_provider_body_or_token(): void
    {
        $cases = [
            400 => 'provider_request_rejected', 401 => 'authentication_error',
            403 => 'permission_denied', 404 => 'phone_number_not_found',
            409 => 'provider_conflict', 429 => 'rate_limited', 500 => 'provider_unavailable',
        ];
        $statusCodes = array_keys($cases);
        $position = 0;
        Http::fake(function () use ($statusCodes, &$position) {
            return Http::response(['error' => ['message' => 'secret '.$this->accessToken]], $statusCodes[$position++]);
        });

        foreach ($cases as $status => $failureKind) {
            try {
                (new MetaCloudWhatsAppProvider)->sendText($this->connection(), new WhatsAppSendTextRequest('6281234567890', 'hello'));
                $this->fail('Provider HTTP error must throw a controlled exception.');
            } catch (WhatsAppProviderException $exception) {
                $this->assertSame($failureKind, $exception->failureKind);
                $this->assertSame($status, $exception->statusCode);
                $this->assertSame('WhatsApp provider request failed.', $exception->getMessage());
                $this->assertStringNotContainsString($this->accessToken, $exception->getMessage());
            }
        }
        Http::assertSentCount(count($cases));
    }

    public function test_transport_malformed_and_unexpected_responses_are_safe(): void
    {
        Http::fake(function () {
            throw new ConnectionException('network error containing '.$this->accessToken);
        });
        try {
            (new MetaCloudWhatsAppProvider)->sendText($this->connection(), new WhatsAppSendTextRequest('6281234567890', 'hello'));
            $this->fail('Transport exception must be controlled.');
        } catch (WhatsAppProviderException $exception) {
            $this->assertSame('transport_error', $exception->failureKind);
            $this->assertStringNotContainsString($this->accessToken, $exception->getMessage());
        }
    }

    public function test_malformed_json_response_is_controlled(): void
    {
        Http::fake(['*' => Http::response('{invalid-json', 200)]);
        try {
            (new MetaCloudWhatsAppProvider)->sendText($this->connection(), new WhatsAppSendTextRequest('6281234567890', 'hello'));
            $this->fail('Malformed response must be controlled.');
        } catch (WhatsAppProviderException $exception) {
            $this->assertSame('malformed_provider_response', $exception->failureKind);
        }
    }

    public function test_success_response_without_message_id_is_rejected_safely(): void
    {
        Http::fake(['*' => Http::response(['messages' => []], 200)]);
        try {
            (new MetaCloudWhatsAppProvider)->sendText($this->connection(), new WhatsAppSendTextRequest('6281234567890', 'hello'));
            $this->fail('Unexpected response must be controlled.');
        } catch (WhatsAppProviderException $exception) {
            $this->assertSame('unexpected_provider_response', $exception->failureKind);
        }
    }

    private function connection(array $overrides = []): WhatsAppConnection
    {
        $organization = Organization::query()->create([
            'name' => 'Provider test', 'slug' => 'provider-'.bin2hex(random_bytes(6)),
        ]);

        return $organization->whatsappConnections()->create(array_merge([
            'provider' => 'meta_cloud', 'display_name' => 'Meta test',
            'phone_number' => '+6281234567890', 'phone_number_id' => 'phone-'.bin2hex(random_bytes(6)),
            'business_account_id' => 'business-'.bin2hex(random_bytes(6)), 'enabled' => true,
            'is_primary' => true, 'status' => 'connected',
            'credentials' => ['access_token' => $this->accessToken],
        ], $overrides));
    }
}
