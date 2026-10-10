<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppOutboundApiTest extends TestCase
{
    use DatabaseTransactions;

    private const META_TOKEN = 'fake-meta-token-for-http-fake-only';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.meta.base_url' => 'https://graph.facebook.com',
            'services.whatsapp.meta.graph_api_version' => 'v23.0',
            'services.whatsapp.meta.connect_timeout' => 2,
            'services.whatsapp.meta.timeout' => 3,
        ]);
    }

    public function test_connection_verification_uses_read_only_meta_phone_number_lookup_and_safe_response(): void
    {
        [$organization, $user, , , $connection] = $this->workspace('admin');
        Http::fake(['*' => Http::response(['data' => [[
            'id' => $connection->phone_number_id,
            'display_phone_number' => '+62 812-3456-7890',
            'verified_name' => 'WABiz Test',
        ]]], 200)]);

        $response = $this->asUser($user)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.provider', 'meta_cloud')
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonMissingPath('data.credentials');

        $this->assertStringNotContainsString(self::META_TOKEN, $response->getContent());
        $this->assertDatabaseHas('whatsapp_connections', [
            'id' => $connection->id, 'organization_id' => $organization->id, 'status' => 'connected',
        ]);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://graph.facebook.com/v23.0/'.$connection->business_account_id.'/phone_numbers?fields=id%2Cdisplay_phone_number%2Cverified_name'
            && $request->hasHeader('Authorization', 'Bearer '.self::META_TOKEN)
        );
    }

    public function test_connection_verification_maps_meta_auth_permission_not_found_rate_limit_and_server_errors(): void
    {
        [$organization, $user, , , $connection] = $this->workspace('admin');
        $cases = [401 => 401, 403 => 403, 404 => 404, 429 => 429, 500 => 503];
        $statuses = array_keys($cases);
        $index = 0;
        Http::fake(function () use ($statuses, &$index) {
            return Http::response(['error' => ['message' => 'private '.$this::META_TOKEN]], $statuses[$index++]);
        });

        foreach ($cases as $metaStatus => $apiStatus) {
            $response = $this->asUser($user)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')
                ->assertStatus($apiStatus)
                ->assertJsonPath('success', false)
                ->assertJsonMissingPath('data.credentials');
            $this->assertStringNotContainsString(self::META_TOKEN, $response->getContent());
        }
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $connection->id, 'organization_id' => $organization->id, 'status' => 'error']);
    }

    public function test_connection_verification_rejects_phone_number_not_listed_under_waba(): void
    {
        [, $user, , , $connection] = $this->workspace('admin');
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $this->asUser($user)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')
            ->assertNotFound()->assertJsonPath('code', 'phone_number_not_found');
    }

    public function test_connection_verification_handles_malformed_meta_response(): void
    {
        [, $user, , , $connection] = $this->workspace('admin');
        Http::fake(['*' => Http::response('{bad-json', 200)]);
        $this->asUser($user)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')
            ->assertStatus(502)->assertJsonPath('code', 'malformed_provider_response');
    }

    public function test_connection_verification_handles_timeout_without_leaking_exception_details(): void
    {
        [, $user, , , $connection] = $this->workspace('admin');
        Http::fake(function () {
            throw new ConnectionException('network detail including '.self::META_TOKEN);
        });
        $response = $this->asUser($user)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')
            ->assertServiceUnavailable()->assertJsonPath('code', 'transport_error');
        $this->assertStringNotContainsString(self::META_TOKEN, $response->getContent());
    }

    public function test_connection_verification_requires_auth_and_owner_or_admin_permission(): void
    {
        [, , , , $connection] = $this->workspace();
        $this->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')->assertUnauthorized();

        $organization = Organization::query()->findOrFail($connection->organization_id);
        $staff = $this->user($organization, 'staff');
        $this->asUser($staff)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')->assertForbidden();

        $owner = $this->user($organization, 'owner');
        Http::fake(['*' => Http::response(['data' => [['id' => $connection->phone_number_id]]], 200)]);
        $this->asUser($owner)->postJson('/api/whatsapp/connections/'.$connection->id.'/verify')->assertOk();
    }

    public function test_successful_human_send_persists_meta_id_and_advances_only_newer_conversation_timestamp(): void
    {
        [, $staff, $client, $conversation] = $this->workspace('staff', [], [], [
            'status' => 'HUMAN_ACTIVE', 'last_message_at' => now()->addDay(),
        ]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.from-meta']]], 200)]);

        $response = $this->send($staff, $conversation, 'A customer-facing answer')
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.sender_type', 'human')
            ->assertJsonPath('data.message_type', 'text')
            ->assertJsonPath('data.body', 'A customer-facing answer')
            ->assertJsonPath('data.external_message_id', 'wamid.from-meta')
            ->assertJsonPath('data.delivery_status', 'sent')
            ->assertJsonPath('data.replayed', false);

        $messageId = $response->json('data.id');
        $this->assertDatabaseHas('messages', [
            'id' => $messageId,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => 'human',
            'message_type' => 'text',
            'body' => 'A customer-facing answer',
            'external_message_id' => 'wamid.from-meta',
            'delivery_status' => 'sent',
        ]);
        $message = $conversation->messages()->findOrFail($messageId);
        $this->assertNotNull($message->sent_at);
        $this->assertSame('HUMAN_ACTIVE', $conversation->fresh()->status);
        $this->assertSame($conversation->last_message_at->getTimestamp(), $conversation->fresh()->last_message_at->getTimestamp());
        $this->assertStringNotContainsString(self::META_TOKEN, $response->getContent());
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'organization_id' => $conversation->organization_id]);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request['to'] === '6281234567890' && $request['type'] === 'text');
    }

    public function test_same_idempotency_key_returns_previous_delivery_without_sending_twice(): void
    {
        [, $staff, , $conversation] = $this->workspace();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.once']]], 200)]);
        $key = 'manual-smoke-key-000001';

        $this->send($staff, $conversation, 'One send only', $key)->assertCreated();
        $this->send($staff, $conversation, 'One send only', $key)
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.external_message_id', 'wamid.once');

        $this->assertSame(1, $conversation->messages()->count());
        Http::assertSentCount(1);
    }

    public function test_reusing_idempotency_key_with_different_body_is_rejected(): void
    {
        [, $staff, , $conversation] = $this->workspace();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.key']]], 200)]);
        $key = 'manual-smoke-key-000002';
        $this->send($staff, $conversation, 'Original body', $key)->assertCreated();
        $this->send($staff, $conversation, 'Changed body', $key)->assertConflict()->assertJsonPath('code', 'idempotency_key_conflict');
        Http::assertSentCount(1);
    }

    public function test_provider_failures_do_not_become_success_and_ambiguous_results_block_retry(): void
    {
        [, $staff, , $conversation] = $this->workspace();
        Http::fakeSequence()
            ->push(['error' => ['message' => 'private']], 400)
            ->push(['error' => ['message' => 'ambiguous']], 500);
        $this->send($staff, $conversation, 'Rejected', 'manual-smoke-key-000003')
            ->assertUnprocessable()->assertJsonPath('success', false);
        $failed = $conversation->messages()->firstOrFail();
        $this->assertSame('failed', $failed->delivery_status);
        $this->assertNull($failed->external_message_id);
        $this->assertNull($failed->sent_at);

        [, $staff2, , $conversation2] = $this->workspace();
        $this->send($staff2, $conversation2, 'Maybe accepted', 'manual-smoke-key-000004')
            ->assertStatus(502)->assertJsonPath('success', false);
        $uncertain = $conversation2->messages()->firstOrFail();
        $this->assertSame('unknown', $uncertain->delivery_status);
        $this->send($staff2, $conversation2, 'Maybe accepted', 'manual-smoke-key-000004')
            ->assertConflict()->assertJsonPath('code', 'delivery_already_attempted');
    }

    public function test_malformed_success_response_is_unknown_without_duplicate_resend(): void
    {
        [, $staff, , $conversation] = $this->workspace();
        Http::fake(['*' => Http::response(['messages' => []], 200)]);
        $this->send($staff, $conversation, 'Malformed response', 'manual-smoke-key-000005')->assertStatus(502);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'delivery_status' => 'unknown', 'external_message_id' => null]);

    }

    public function test_outbound_timeout_is_unknown_and_does_not_expose_transport_error(): void
    {
        [, $staff2, , $conversation2] = $this->workspace();
        Http::fake(function () {
            throw new ConnectionException('private timeout detail '.self::META_TOKEN);
        });
        $response = $this->send($staff2, $conversation2, 'Timed out', 'manual-smoke-key-000006')->assertStatus(502);
        $this->assertStringNotContainsString(self::META_TOKEN, $response->getContent());
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation2->id, 'delivery_status' => 'unknown']);
    }

    public function test_inactive_unsupported_and_missing_credential_connections_are_safe(): void
    {
        [, $staff, , $conversation, $connection] = $this->workspace();
        Http::preventStrayRequests();
        $connection->forceFill(['enabled' => false])->save();
        $this->send($staff, $conversation, 'No send', 'manual-smoke-key-000007')
            ->assertUnprocessable()->assertJsonPath('code', 'connection_inactive');
        $this->assertDatabaseCount('messages', 0);

        $connection->forceFill(['enabled' => true, 'provider' => 'unknown_provider'])->save();
        $this->send($staff, $conversation, 'No fallback', 'manual-smoke-key-000008')
            ->assertUnprocessable()->assertJsonPath('code', 'unsupported_provider');
        $this->assertDatabaseCount('messages', 0);

        $connection->forceFill(['provider' => 'meta_cloud', 'credentials' => ['webhook_secret' => 'only-webhook-secret']])->save();
        $response = $this->send($staff, $conversation, 'No token', 'manual-smoke-key-000009')
            ->assertUnprocessable()->assertJsonPath('code', 'missing_credentials');
        $this->assertStringNotContainsString('only-webhook-secret', $response->getContent());
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'delivery_status' => 'failed']);
    }

    public function test_tenant_isolation_rejects_foreign_conversation_client_and_connection(): void
    {
        [, $user] = $this->workspace();
        [, , , $foreignConversation] = $this->workspace();
        Http::preventStrayRequests();
        $this->send($user, $foreignConversation, 'Cross tenant', 'manual-smoke-key-000010')->assertNotFound();
        $this->assertDatabaseCount('messages', 0);

        [$organizationA, $staffA, , $conversationA] = $this->workspace();
        $otherOrganization = $this->organization();
        $foreignClient = $otherOrganization->clients()->create([
            'name' => 'Foreign client', 'phone_number' => '+6281111111111', 'status' => 'new',
        ]);
        $conversationA->forceFill(['client_id' => $foreignClient->id])->save();
        $this->send($staffA, $conversationA, 'Foreign client', 'manual-smoke-key-000011')->assertNotFound();
        $this->assertDatabaseCount('messages', 0);

        $foreignOrganization = $this->organization();
        $this->connection($foreignOrganization);
        [$orgWithoutConnection, $staffWithoutConnection, , $conversationWithoutConnection] = $this->workspace('staff', ['with_connection' => false]);
        $this->assertNotSame($foreignOrganization->id, $orgWithoutConnection->id);
        $this->send($staffWithoutConnection, $conversationWithoutConnection, 'Foreign connection', 'manual-smoke-key-000012')
            ->assertUnprocessable()->assertJsonPath('code', 'connection_inactive');
    }

    public function test_sender_type_cannot_be_forged_and_send_requires_auth(): void
    {
        [, $staff, , $conversation] = $this->workspace();
        Http::preventStrayRequests();
        $uri = '/api/conversations/'.$conversation->id.'/messages/send';
        $this->postJson($uri, ['body' => 'AI spoof', 'sender_type' => 'ai'], ['Idempotency-Key' => 'manual-smoke-key-000013'])
            ->assertUnauthorized();
        $this->send($staff, $conversation, 'AI spoof', 'manual-smoke-key-000014', ['sender_type' => 'ai'])->assertUnprocessable();
        $this->send($staff, $conversation, '   ', 'manual-smoke-key-000015')->assertUnprocessable();
        $this->send($staff, $conversation, str_repeat('x', 4097), 'manual-smoke-key-000016')->assertUnprocessable();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_owner_admin_and_staff_may_send_while_unauthenticated_users_cannot(): void
    {
        [$organization, $staff, , $conversation] = $this->workspace('staff');
        $admin = $this->user($organization, 'admin');
        $owner = $this->user($organization, 'owner');
        Http::fakeSequence()
            ->push(['messages' => [['id' => 'wamid.staff']]], 200)
            ->push(['messages' => [['id' => 'wamid.admin']]], 200)
            ->push(['messages' => [['id' => 'wamid.owner']]], 200);

        $this->postJson('/api/conversations/'.$conversation->id.'/messages/send', ['body' => 'No auth'])->assertUnauthorized();
        $this->send($staff, $conversation, 'Staff message', 'manual-smoke-key-000017')->assertCreated();
        $this->send($admin, $conversation, 'Admin message', 'manual-smoke-key-000018')->assertCreated();
        $this->send($owner, $conversation, 'Owner message', 'manual-smoke-key-000019')->assertCreated();
        $this->assertDatabaseCount('messages', 3);
        Http::assertSentCount(3);
    }

    private function send(User $user, Conversation $conversation, string $body, ?string $key = null, array $extra = []): TestResponse
    {
        $key ??= 'manual-smoke-key-'.bin2hex(random_bytes(8));

        return $this->asUser($user)->postJson(
            '/api/conversations/'.$conversation->id.'/messages/send',
            ['body' => $body, ...$extra],
            ['Idempotency-Key' => $key],
        );
    }

    private function asUser(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('outbound-test')->plainTextToken);
    }

    /** @return array{Organization, User, Client, Conversation, WhatsAppConnection|null} */
    private function workspace(string $role = 'staff', array $connectionOverrides = [], array $clientOverrides = [], array $conversationOverrides = []): array
    {
        $organization = $this->organization();
        $user = $this->user($organization, $role);
        $member = CsMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'display_name' => 'Assigned outbound operator',
            'active' => true,
        ]);
        $client = $organization->clients()->create(array_merge([
            'name' => 'Outbound test client', 'phone_number' => '081234567890', 'status' => 'customer',
        ], $clientOverrides));
        $conversation = $organization->conversations()->create(array_merge([
            'client_id' => $client->id,
            'cs_member_id' => $member->id,
            'status' => 'HUMAN_ACTIVE',
            'channel' => 'whatsapp',
            'started_at' => now(),
        ], $conversationOverrides));
        $conversation->forceFill(['cs_member_id' => $member->id])->save();
        $connection = null;
        if (($connectionOverrides['with_connection'] ?? true) !== false) {
            unset($connectionOverrides['with_connection']);
            $connection = $this->connection($organization, $connectionOverrides);
        }

        return [$organization, $user, $client, $conversation, $connection];
    }

    private function organization(): Organization
    {
        return Organization::query()->create(['name' => 'Outbound test', 'slug' => 'outbound-'.bin2hex(random_bytes(6))]);
    }

    private function user(Organization $organization, string $role): User
    {
        return User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Outbound '.$role,
            'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    private function connection(Organization $organization, array $overrides = []): WhatsAppConnection
    {
        return $organization->whatsappConnections()->create(array_merge([
            'provider' => 'meta_cloud',
            'display_name' => 'Outbound test Meta',
            'phone_number' => '+6281111111111',
            'phone_number_id' => 'phone-'.bin2hex(random_bytes(5)),
            'business_account_id' => 'waba-'.bin2hex(random_bytes(5)),
            'enabled' => true,
            'status' => 'connected',
            'is_primary' => true,
            'credentials' => ['access_token' => self::META_TOKEN],
        ], $overrides));
    }
}
