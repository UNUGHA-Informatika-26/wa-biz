<?php

namespace Tests\Feature;

use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WppConnectGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppConnectionApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_connection_routes_require_authentication(): void
    {
        $this->getJson('/api/whatsapp/connections')->assertUnauthorized();
        $this->postJson('/api/whatsapp/connections', [])->assertUnauthorized();
    }

    public function test_connections_are_authenticated_tenant_scoped_and_credentials_are_never_returned(): void
    {
        $organization = $this->organization();
        $otherOrganization = $this->organization();
        $admin = $this->user($organization, 'admin');
        $owner = $this->user($organization, 'owner');
        $otherAdmin = $this->user($otherOrganization, 'admin');
        $staff = $this->user($organization, 'staff');
        $secret = bin2hex(random_bytes(32));
        $token = $this->token($admin);
        $payload = $this->payload('primary', $secret);

        $this->apiPost($token, '/api/whatsapp/connections', $payload)
            ->assertCreated()->assertJsonMissingPath('data.credentials')->assertJsonMissingPath('data.organization_id');
        $connection = WhatsAppConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->assertNotSame($secret, $connection->getRawOriginal('credentials'));
        $this->assertSame($payload['credentials'], $connection->credentials);

        $this->apiGet($this->token($otherAdmin), '/api/whatsapp/connections/'.$connection->id)->assertNotFound();
        $this->apiPost($this->token($staff), '/api/whatsapp/connections', $payload)
            ->assertForbidden();
        $this->apiGet($token, '/api/whatsapp/connections')
            ->assertOk()->assertJsonMissingPath('data.0.credentials')->assertJsonMissingPath('data.0.organization_id');
        $this->apiGet($token, '/api/whatsapp/connections/'.$connection->id.'/status')
            ->assertOk()->assertJsonMissingPath('data.credentials');
        $this->apiGet($token, '/api/whatsapp/connections/'.$connection->id)
            ->assertOk()->assertJsonPath('data.id', $connection->id)->assertJsonMissingPath('data.credentials');
        $this->apiPost($this->token($owner), '/api/whatsapp/connections', $this->payload('owner-create', bin2hex(random_bytes(32))))
            ->assertCreated();
    }

    public function test_connection_update_preserves_omitted_credentials_and_primary_demotes_without_deleting(): void
    {
        $organization = $this->organization();
        $admin = $this->user($organization, 'owner');
        $token = $this->token($admin);
        $this->apiPost($token, '/api/whatsapp/connections', $this->payload('first', bin2hex(random_bytes(32))))->assertCreated();
        $first = WhatsAppConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->apiPost($token, '/api/whatsapp/connections', $this->payload('second', bin2hex(random_bytes(32))))->assertCreated();
        $second = WhatsAppConnection::query()->where('organization_id', $organization->id)->whereKeyNot($first->id)->firstOrFail();

        $this->apiPut($token, '/api/whatsapp/connections/'.$second->id, ['is_primary' => true])
            ->assertOk()->assertJsonPath('data.is_primary', true);
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $first->id, 'is_primary' => false]);
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $second->id]);

        $priorCredentials = $second->credentials;
        $this->apiPut($token, '/api/whatsapp/connections/'.$second->id, ['display_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.status', 'disconnected');
        $second->refresh();
        $this->assertSame($priorCredentials, $second->credentials);
    }

    public function test_staff_can_read_but_cannot_mutate_connection_and_owner_can_delete(): void
    {
        $organization = $this->organization();
        $owner = $this->user($organization, 'owner');
        $staff = $this->user($organization, 'staff');
        $connection = $organization->whatsappConnections()->create($this->payload('delete-me', bin2hex(random_bytes(32))));
        $this->apiPut($this->token($staff), '/api/whatsapp/connections/'.$connection->id, ['enabled' => true])->assertForbidden();
        $this->apiDelete($this->token($owner), '/api/whatsapp/connections/'.$connection->id)->assertOk();
        $this->assertDatabaseMissing('whatsapp_connections', ['id' => $connection->id]);
    }

    public function test_primary_connection_is_unique_and_other_connections_are_retained(): void
    {
        $organization = $this->organization();
        $owner = $this->user($organization, 'owner');
        $token = $this->token($owner);
        $this->apiPost($token, '/api/whatsapp/connections', $this->payload('primary-a', bin2hex(random_bytes(32))))->assertCreated();
        $first = WhatsAppConnection::query()->where('organization_id', $organization->id)->firstOrFail();
        $this->apiPost($token, '/api/whatsapp/connections', $this->payload('primary-b', bin2hex(random_bytes(32))))->assertCreated();
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $first->id, 'is_primary' => false]);
        $this->assertDatabaseCount('whatsapp_connections', 2);
        $this->assertSame(1, WhatsAppConnection::query()->where('organization_id', $organization->id)->where('is_primary', true)->count());
    }

    public function test_wppconnect_qr_lifecycle_stays_tenant_scoped_and_hides_gateway_token(): void
    {
        Http::preventStrayRequests();
        config(['services.wppconnect.secret_key' => 'test-gateway-secret']);
        $gatewayMode = 'qr';
        Http::fake(function ($request) use (&$gatewayMode) {
            $url = $request->url();
            if (str_ends_with($url, '/generate-token')) {
                $segments = explode('/', (string) parse_url($url, PHP_URL_PATH));

                return Http::response(['full' => $segments[2].':opaque-session-token'], 201);
            }
            if (str_ends_with($url, '/start-session')) {
                return Http::response(['status' => 'success']);
            }
            if (str_ends_with($url, '/status-session')) {
                return Http::response(['status' => $gatewayMode === 'qr' ? 'QRCODE' : 'CONNECTED']);
            }
            if (str_ends_with($url, '/qrcode-session')) {
                return Http::response('png-bytes', 200, ['Content-Type' => 'image/png']);
            }
            if (str_ends_with($url, '/get-phone-number')) {
                return Http::response(['response' => '6281234567890@c.us']);
            }
            if (str_ends_with($url, '/send-message')) {
                return Http::response(['status' => 'success', 'response' => [['id' => 'fake-external-message-id']]], 201);
            }
            if (str_ends_with($url, '/logout-session')) {
                return Http::response(['status' => 'success']);
            }

            return Http::response([], 404);
        });

        $organization = $this->organization();
        $anotherOrganization = $this->organization();
        $admin = $this->user($organization, 'admin');
        $otherAdmin = $this->user($anotherOrganization, 'admin');
        $token = $this->token($admin);
        $created = $this->apiPost($token, '/api/whatsapp/connections', [
            'provider' => 'wppconnect',
            'display_name' => 'QR connection',
            'phone_number' => '',
        ])->assertCreated()->assertJsonMissingPath('data.credentials');
        $connectionId = (int) $created->json('data.id');

        $this->apiPost($token, "/api/whatsapp/connections/{$connectionId}/start", [])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'qr_ready')
            ->assertDontSee('opaque-session-token');
        $this->apiGet($token, "/api/whatsapp/connections/{$connectionId}/qr")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonPath('data.base64', base64_encode('png-bytes'))
            ->assertDontSee('opaque-session-token');
        $this->apiPost($this->token($otherAdmin), "/api/whatsapp/connections/{$connectionId}/start", [])
            ->assertNotFound();

        $connection = WhatsAppConnection::query()->findOrFail($connectionId);
        $this->assertSame('qr_ready', $connection->status);
        $this->assertSame($connection->gateway_session_ref.':opaque-session-token', $connection->credentials['wppconnect_token']);
        $this->assertNotEmpty($connection->gateway_session_ref);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/generate-token'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/qrcode-session')
            && $request->hasHeader('Authorization', 'Bearer opaque-session-token'));

        $gatewayMode = 'connected';
        $this->apiGet($token, "/api/whatsapp/connections/{$connectionId}/status")
            ->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.phone_number', '+6281234567890');

        $connection->refresh();
        $sendResult = app(WppConnectGateway::class)->sendText(
            $connection,
            new WhatsAppSendTextRequest('+6281234567899', 'test payload only'),
        );
        $this->assertSame('fake-external-message-id', $sendResult->externalMessageId);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/send-message')
            && $request->data()['phone'] === ['6281234567899']);

        $gatewayMode = 'disconnect';
        $this->apiPost($token, "/api/whatsapp/connections/{$connectionId}/disconnect", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'disconnected');
        $connection->refresh();
        $this->assertFalse($connection->enabled);
        $this->assertArrayNotHasKey('wppconnect_token', $connection->credentials ?? []);
    }

    public function test_wppconnect_disconnect_retries_close_after_gateway_error(): void
    {
        Http::preventStrayRequests();
        $closeAttempts = 0;
        Http::fake(function ($request) use (&$closeAttempts) {
            if (str_ends_with($request->url(), '/close-session')) {
                $closeAttempts++;

                return Http::response(['status' => $closeAttempts === 1 ? false : true], $closeAttempts === 1 ? 500 : 200);
            }

            return Http::response([], 404);
        });

        $organization = $this->organization();
        $session = (string) Str::uuid();
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect',
            'display_name' => 'QR close retry',
            'phone_number' => '',
            'status' => 'qr_ready',
            'enabled' => false,
            'credentials' => ['wppconnect_token' => $session.':test-hash'],
            'gateway_session_ref' => $session,
        ]);

        app(WppConnectGateway::class)->disconnect($connection);

        $this->assertSame(2, $closeAttempts);
        $this->assertSame('disconnected', $connection->refresh()->status);
        $this->assertArrayNotHasKey('wppconnect_token', $connection->credentials ?? []);
    }

    private function organization(): Organization
    {
        return Organization::query()->create(['name' => 'WA test', 'slug' => 'wa-'.bin2hex(random_bytes(6))]);
    }

    private function user(Organization $organization, string $role): User
    {
        return User::forceCreate(['organization_id' => $organization->id, 'name' => 'WhatsApp test', 'email' => bin2hex(random_bytes(6)).'@example.test', 'password' => 'password', 'role' => $role]);
    }

    private function token(User $user): string
    {
        return $user->createToken('whatsapp-test')->plainTextToken;
    }

    private function apiGet(string $token, string $uri): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /** @param array<string, mixed> $data */
    private function apiPost(string $token, string $uri, array $data): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson($uri, $data);
    }

    /** @param array<string, mixed> $data */
    private function apiPut(string $token, string $uri, array $data): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->putJson($uri, $data);
    }

    private function apiDelete(string $token, string $uri): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->deleteJson($uri);
    }

    /** @return array<string, mixed> */
    private function payload(string $suffix, string $secret): array
    {
        return [
            'provider' => 'manual', 'display_name' => 'WA '.$suffix, 'phone_number' => '+6281234567890',
            'phone_number_id' => 'phone-'.$suffix, 'business_account_id' => 'business-'.$suffix,
            'enabled' => true, 'is_primary' => true,
            'credentials' => ['webhook_secret' => $secret, 'webhook_verify_token' => 'verify-'.$suffix],
        ];
    }
}
