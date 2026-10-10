<?php

namespace Tests\Feature;

use App\DataTransferObjects\AI\AiMessageProcessingResult;
use App\DataTransferObjects\AI\AiMessageProcessingStatus;
use App\Models\CsMember;
use App\Models\Message;
use App\Models\Organization;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppWebhookEvent;
use App\Services\AI\AiMessageProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verification_requires_matching_enabled_connection_and_returns_plain_challenge(): void
    {
        [$organization, $connection] = $this->connection();
        $this->get('/api/webhooks/whatsapp/manual?phone_number_id=phone-1&verify_token=bad&challenge=abc')
            ->assertUnauthorized();
        $this->get('/api/webhooks/whatsapp/manual?phone_number_id=missing&verify_token=verify-token&challenge=abc')
            ->assertUnauthorized();
        $this->get('/api/webhooks/whatsapp/manual?phone_number_id=phone-1&verify_token=verify-token&challenge=challenge-123')
            ->assertOk()->assertSeeText('challenge-123')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $connection->id, 'status' => 'connected']);
    }

    public function test_signed_inbound_text_is_normalized_stored_and_duplicate_delivery_is_idempotent(): void
    {
        [$organization, $connection, $secret] = $this->connection();
        $legacyClient = $organization->clients()->create(['name' => 'Existing', 'phone_number' => '0812 3456 7890', 'status' => 'customer']);
        $payload = [
            'phone_number_id' => 'phone-1',
            'messages' => [[
                'id' => 'event-1', 'from' => '+62 812-3456-7890', 'type' => 'text',
                'text' => ['body' => 'Halo dari pelanggan'], 'profile_name' => 'Untrusted profile', 'timestamp' => 1780000000,
                'organization_id' => 999999, 'client_id' => 999999, 'conversation_id' => 999999,
                'direction' => 'outbound', 'sender_type' => 'ai',
            ]],
        ];
        $first = $this->postSigned($payload, $secret)->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('duplicates', 0);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('conversations', ['id' => $organization->conversations()->value('id'), 'unread_count' => 1]);
        $this->assertDatabaseHas('clients', ['id' => $legacyClient->id, 'phone_number' => '0812 3456 7890']);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $organization->conversations()->value('id'), 'direction' => 'inbound',
            'sender_type' => 'customer', 'message_type' => 'text', 'external_message_id' => 'event-1', 'body' => 'Halo dari pelanggan',
        ]);
        $event = WhatsAppWebhookEvent::query()->where('external_event_id', 'event-1')->firstOrFail();
        $this->assertSame('processed', $event->status);
        $this->assertArrayNotHasKey('organization_id', $event->payload);
        $this->assertArrayNotHasKey('client_id', $event->payload);
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $connection->id, 'last_webhook_at' => $connection->fresh()->last_webhook_at]);

        $this->postSigned($payload, $secret)->assertOk()->assertJsonPath('processed', 0)->assertJsonPath('duplicates', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('conversations', ['id' => $organization->conversations()->value('id'), 'unread_count' => 1]);
    }

    public function test_invalid_signature_and_non_text_payload_do_not_persist_events(): void
    {
        [$organization, , $secret] = $this->connection();
        $payload = ['phone_number_id' => 'phone-1', 'messages' => [[
            'id' => 'invalid-event', 'from' => '081234567890', 'type' => 'text', 'text' => ['body' => 'Hello'],
        ]]];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/webhooks/whatsapp/manual', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WABIZ_SIGNATURE' => 'sha256='.str_repeat('0', 64)], $raw)->assertUnauthorized();
        $payload['messages'][0]['type'] = 'image';
        $this->postSigned($payload, $secret)->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_resolved_conversation_is_preserved_and_new_inbound_creates_a_fresh_one(): void
    {
        [$organization, , $secret] = $this->connection();
        $client = $organization->clients()->create(['name' => 'Customer', 'phone_number' => '+6281234567890', 'status' => 'customer']);
        $oldConversation = $organization->conversations()->create([
            'client_id' => $client->id, 'status' => 'RESOLVED', 'channel' => 'whatsapp', 'started_at' => now(), 'resolved_at' => now(),
        ]);
        $this->postSigned(['phone_number_id' => 'phone-1', 'messages' => [[
            'id' => 'event-new-thread', 'from' => '081234567890', 'type' => 'text', 'text' => ['body' => 'New question'],
        ]]], $secret)->assertOk();
        $this->assertDatabaseCount('conversations', 2);
        $this->assertDatabaseHas('conversations', ['id' => $oldConversation->id, 'status' => 'RESOLVED']);
        $this->assertDatabaseHas('conversations', ['client_id' => $client->id, 'status' => 'AI_ACTIVE', 'channel' => 'whatsapp']);
    }

    public function test_new_client_is_created_with_canonical_phone_and_active_conversation_is_reused(): void
    {
        [$organization, , $secret] = $this->connection();
        $client = $organization->clients()->create(['name' => 'Customer', 'phone_number' => '+6281230000000', 'status' => 'customer']);
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id, 'status' => 'HUMAN_ACTIVE', 'channel' => 'whatsapp', 'started_at' => now()->subHour(),
        ]);
        $this->postSigned(['phone_number_id' => 'phone-1', 'messages' => [[
            'id' => 'reuse-active', 'from' => '081230000000', 'type' => 'text', 'text' => ['body' => 'Continue'],
        ]]], $secret)->assertOk()->assertJsonPath('processed', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'external_message_id' => 'reuse-active']);
        $this->assertNotNull($conversation->fresh()->last_message_at);

        $this->postSigned(['phone_number_id' => 'phone-1', 'messages' => [[
            'id' => 'new-client', 'from' => '+62 877-1000-0000', 'type' => 'text',
            'text' => ['body' => 'First message'], 'profile_name' => 'New Customer',
        ]]], $secret)->assertOk();
        $this->assertDatabaseHas('clients', ['phone_number' => '+6287710000000', 'name' => 'New Customer', 'status' => 'new']);
        $this->assertDatabaseCount('clients', 2);
        $this->assertDatabaseCount('conversations', 2);
    }

    public function test_processing_failure_is_recorded_safely_and_rolls_back_business_rows(): void
    {
        [$organization, , $secret] = $this->connection();
        DB::statement("CREATE OR REPLACE FUNCTION wabiz_test_fail_message_insert() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'test failure'; END; \$\$");
        DB::statement('CREATE TRIGGER wabiz_test_fail_message_insert BEFORE INSERT ON messages FOR EACH ROW EXECUTE FUNCTION wabiz_test_fail_message_insert()');

        $this->postSigned(['phone_number_id' => 'phone-1', 'messages' => [[
            'id' => 'failed-event', 'from' => '081299999999', 'type' => 'text', 'text' => ['body' => 'Persist only safe failure'],
        ]]], $secret)->assertStatus(500)->assertJsonMissingPath('exception');
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'organization_id' => $organization->id, 'external_event_id' => 'failed-event',
            'status' => 'failed', 'error_message' => 'Inbound webhook processing failed.',
        ]);
    }

    public function test_wppconnect_payload_is_tenant_connection_scoped_and_idempotent(): void
    {
        $organization = Organization::query()->create(['name' => 'WPP webhook', 'slug' => 'wpp-'.bin2hex(random_bytes(6))]);
        $session = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        config(['services.wppconnect.webhook_token' => $token]);
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'WPP Main', 'phone_number' => '+6281111111111',
            'enabled' => true, 'is_primary' => true, 'status' => 'connected', 'gateway_session_ref' => $session,
        ]);
        $client = $organization->clients()->create(['name' => 'WPP Client', 'phone_number' => '+6281234567890', 'status' => 'customer']);
        $payload = [
            'event' => 'onmessage', 'session' => $session, 'id' => 'true_6281234567890@c.us_A1B2',
            'from' => '6281234567890@c.us', 'to' => '6281111111111@c.us', 'fromMe' => false,
            'isGroupMsg' => false, 'type' => 'chat', 'body' => 'Halo melalui WPPConnect', 't' => 1780000000,
            'notifyName' => 'WPP Client',
        ];
        $uri = '/api/webhooks/whatsapp/wppconnect/'.$token;
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('processed', 1);
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('processed', 0)->assertJsonPath('duplicates', 1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $conversationId = $organization->conversations()->value('id');
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'organization_id' => $organization->id,
            'whatsapp_connection_id' => $connection->id,
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => 'Halo melalui WPPConnect',
            'external_message_id' => 'wppconnect:'.$session.':'.$payload['id'],
        ]);
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'organization_id' => $organization->id,
            'whatsapp_connection_id' => $connection->id,
            'provider' => 'wppconnect',
            'status' => 'processed',
        ]);
    }

    public function test_new_wppconnect_inbound_message_is_passed_to_ai_once_after_persistence(): void
    {
        $organization = Organization::query()->create(['name' => 'WPP AI trigger', 'slug' => 'wpp-ai-'.bin2hex(random_bytes(6))]);
        $session = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        config(['services.wppconnect.webhook_token' => $token]);
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'WPP AI', 'phone_number' => '+6281111111111',
            'enabled' => true, 'gateway_session_ref' => $session,
        ]);
        $processor = Mockery::mock(AiMessageProcessor::class);
        $processor->shouldReceive('process')->once()->with(Mockery::on(
            fn (Message $message): bool => $message->exists
                && $message->direction === 'inbound'
                && $message->body === 'Please answer using organization settings'
                && $message->conversation()->first()?->organization_id === $organization->id
                && $message->conversation()->first()?->whatsapp_connection_id === $connection->id,
        ))->andReturn(new AiMessageProcessingResult(AiMessageProcessingStatus::PROCESSED, 'test_result'));
        $this->app->instance(AiMessageProcessor::class, $processor);

        $payload = [
            'event' => 'onmessage', 'session' => $session, 'id' => 'ai-trigger-message',
            'from' => '6281234567890@c.us', 'fromMe' => false, 'isGroupMsg' => false,
            'type' => 'chat', 'body' => 'Please answer using organization settings',
        ];
        $uri = '/api/webhooks/whatsapp/wppconnect/'.$token;
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('processed', 1);
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('duplicates', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_synthetic_wppconnect_registration_event_persists_handoff_and_duplicate_is_idempotent(): void
    {
        $organization = Organization::query()->create(['name' => 'WPP registration handoff', 'slug' => 'wpp-reg-'.bin2hex(random_bytes(6))]);
        $department = $organization->departments()->create(['name' => 'Pendaftaran', 'active' => true]);
        $member = CsMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => null,
            'department_id' => $department->id,
            'display_name' => 'CS Pendaftaran',
            'phone_number' => '+6281234567891',
            'active' => true,
        ]);
        $organization->aiAssistantSetting()->create([
            'enabled' => true,
            'registration_enabled' => true,
            'registration_department_id' => $department->id,
        ]);
        $session = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        config(['services.wppconnect.webhook_token' => $token]);
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'Registration route', 'phone_number' => '+6281111111111',
            'enabled' => true, 'gateway_session_ref' => $session,
        ]);
        $payload = [
            'event' => 'onmessage', 'session' => $session, 'id' => 'registration-intent-once',
            'from' => '6281234567890@c.us', 'fromMe' => false, 'isGroupMsg' => false,
            'type' => 'chat', 'body' => 'Saya mau daftar',
        ];
        $uri = '/api/webhooks/whatsapp/wppconnect/'.$token;

        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('processed', 1);
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('duplicates', 1);

        $payload['id'] = 'registration-followup-after-handoff';
        $payload['body'] = 'Saya ingin bertanya lagi';
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('processed', 1);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('conversations', [
            'organization_id' => $organization->id,
            'whatsapp_connection_id' => $connection->id,
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
            'status' => 'TRANSFERRED_TO_HUMAN',
            'handoff_reason' => 'registration_department_handoff',
        ]);
        $this->assertDatabaseHas('messages', [
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => 'Saya mau daftar',
        ]);
        $this->assertDatabaseHas('messages', [
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'body' => 'Saya ingin bertanya lagi',
        ]);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_wppconnect_rejects_invalid_token_unknown_session_and_ignores_group_or_outgoing_events(): void
    {
        $organization = Organization::query()->create(['name' => 'WPP security', 'slug' => 'wpps-'.bin2hex(random_bytes(6))]);
        $session = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        config(['services.wppconnect.webhook_token' => $token]);
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'WPP', 'phone_number' => '+6281111111111',
            'enabled' => true, 'gateway_session_ref' => $session,
        ]);
        $uri = '/api/webhooks/whatsapp/wppconnect/'.$token;
        $payload = ['event' => 'onmessage', 'session' => $session, 'id' => 'event-x', 'from' => '6281234567890@c.us', 'fromMe' => false, 'type' => 'chat', 'body' => 'Hi'];
        $this->postJson('/api/webhooks/whatsapp/wppconnect/'.str_repeat('a', 64), $payload)->assertUnauthorized();
        $payload['session'] = (string) Str::uuid();
        $this->postJson($uri, $payload)->assertNotFound();
        $payload['session'] = $session;
        $payload['from'] = '120363000000000000@g.us';
        $this->postJson($uri, $payload)->assertStatus(202)->assertJsonPath('ignored', 1);
        $payload['from'] = '6281234567890@c.us';
        $payload['fromMe'] = true;
        $this->postJson($uri, $payload)->assertStatus(202)->assertJsonPath('ignored', 1);
        $payload['fromMe'] = false;
        $payload['event'] = 'onselfmessage';
        $this->postJson($uri, $payload)->assertStatus(202)->assertJsonPath('ignored', 1);
        $payload['event'] = 'onack';
        unset($payload['from'], $payload['id'], $payload['type'], $payload['body']);
        $this->postJson($uri, $payload)->assertStatus(202)->assertJsonPath('ignored', 1);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);

        $client = $organization->clients()->create(['name' => 'Outbound target', 'phone_number' => '+6281234567890', 'status' => 'customer']);
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id,
            'whatsapp_connection_id' => $connection->id,
            'channel' => 'whatsapp',
            'status' => 'HUMAN_ACTIVE',
        ]);
        $conversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'human', 'message_type' => 'text',
            'body' => 'Already stored by sender', 'external_message_id' => 'sent-wpp-id',
            'delivery_status' => 'sent', 'metadata' => ['provider' => 'wppconnect'],
        ]);
        $this->postJson($uri, [
            'event' => 'onselfmessage', 'session' => $session, 'id' => 'sent-wpp-id',
            'from' => '6281111111111@c.us', 'to' => '6281234567890@c.us', 'fromMe' => true,
            'isGroupMsg' => false, 'type' => 'chat', 'body' => 'Already stored by sender',
        ])->assertStatus(202)->assertJsonPath('ignored', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_rotated_wppconnect_callback_token_rejects_invalid_and_accepts_current(): void
    {
        $invalidToken = str_repeat('0', 64);
        $currentToken = (string) config('services.wppconnect.webhook_token');
        $this->assertNotSame($invalidToken, $currentToken);

        $organization = Organization::query()->create(['name' => 'Rotated callback', 'slug' => 'rotated-'.bin2hex(random_bytes(6))]);
        $session = (string) Str::uuid();
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'WPP', 'phone_number' => '+6281111111111',
            'enabled' => true, 'gateway_session_ref' => $session,
        ]);
        $processor = Mockery::mock(AiMessageProcessor::class);
        $processor->shouldReceive('process')->once()->andReturn(new AiMessageProcessingResult(AiMessageProcessingStatus::PROCESSED, 'test_result'));
        $this->app->instance(AiMessageProcessor::class, $processor);

        $payload = [
            'event' => 'onmessage', 'session' => $session, 'id' => 'rotated-callback-message',
            'from' => '6281234567890@c.us', 'fromMe' => false, 'isGroupMsg' => false,
            'type' => 'chat', 'body' => 'Safe callback rotation verification',
        ];
        $this->postJson('/api/webhooks/whatsapp/wppconnect/'.$invalidToken, $payload)->assertUnauthorized();
        $this->postJson('/api/webhooks/whatsapp/wppconnect/'.$currentToken, $payload)
            ->assertOk()->assertJsonPath('processed', 1);

        $this->assertDatabaseHas('conversations', [
            'organization_id' => $organization->id,
            'whatsapp_connection_id' => $connection->id,
        ]);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_inbound_after_reset_is_saved_in_the_current_generation(): void
    {
        $organization = Organization::query()->create(['name' => 'WPP reset', 'slug' => 'wpp-reset-'.bin2hex(random_bytes(6))]);
        $session = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        config(['services.wppconnect.webhook_token' => $token]);
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'WPP', 'phone_number' => '+6281111111111',
            'enabled' => true, 'gateway_session_ref' => $session,
        ]);
        $client = $organization->clients()->create(['name' => 'Reset client', 'phone_number' => '+6281234567890', 'status' => 'customer']);
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id, 'whatsapp_connection_id' => $connection->id,
            'channel' => 'whatsapp', 'status' => 'AI_ACTIVE',
        ]);
        $conversation->messages()->create([
            'direction' => 'inbound', 'sender_type' => 'customer', 'message_type' => 'text',
            'body' => 'Old generation', 'external_message_id' => 'old-generation',
        ]);
        $conversation->forceFill(['history_generation' => 2])->save();

        $this->postJson('/api/webhooks/whatsapp/wppconnect/'.$token, [
            'event' => 'onmessage', 'session' => $session, 'id' => 'new-generation',
            'from' => '6281234567890@c.us', 'fromMe' => false, 'isGroupMsg' => false,
            'type' => 'chat', 'body' => 'Current generation', 't' => 1791345600,
        ])->assertOk()->assertJsonPath('processed', 1);

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'external_message_id' => 'old-generation', 'history_generation' => 1]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'external_message_id' => 'wppconnect:'.$session.':new-generation', 'history_generation' => 2]);
    }

    public function test_same_client_is_kept_in_separate_conversations_for_different_whatsapp_connections(): void
    {
        $organization = Organization::query()->create(['name' => 'Multi connection', 'slug' => 'multi-'.bin2hex(random_bytes(6))]);
        $token = bin2hex(random_bytes(32));
        config(['services.wppconnect.webhook_token' => $token]);
        $sessionA = (string) Str::uuid();
        $sessionB = (string) Str::uuid();
        $connectionA = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'One', 'phone_number' => '+6281111111111',
            'enabled' => true, 'gateway_session_ref' => $sessionA,
        ]);
        $connectionB = $organization->whatsappConnections()->create([
            'provider' => 'wppconnect', 'display_name' => 'Two', 'phone_number' => '+6281111111112',
            'enabled' => true, 'gateway_session_ref' => $sessionB,
        ]);
        $base = ['event' => 'onmessage', 'id' => 'true_6281234567890@c.us_message', 'from' => '6281234567890@c.us', 'fromMe' => false, 'type' => 'chat', 'body' => 'Same client'];
        $this->postJson('/api/webhooks/whatsapp/wppconnect/'.$token, [...$base, 'session' => $sessionA])->assertOk();
        $this->postJson('/api/webhooks/whatsapp/wppconnect/'.$token, [...$base, 'session' => $sessionB])->assertOk();

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('conversations', 2);
        $this->assertDatabaseHas('conversations', ['organization_id' => $organization->id, 'whatsapp_connection_id' => $connectionA->id]);
        $this->assertDatabaseHas('conversations', ['organization_id' => $organization->id, 'whatsapp_connection_id' => $connectionB->id]);
    }

    /** @return array{Organization, WhatsAppConnection, string} */
    private function connection(): array
    {
        $organization = Organization::query()->create(['name' => 'Webhook test', 'slug' => 'hook-'.bin2hex(random_bytes(6))]);
        $secret = bin2hex(random_bytes(32));
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'manual', 'display_name' => 'Test WhatsApp', 'phone_number' => '+6281111111111',
            'phone_number_id' => 'phone-1', 'enabled' => true, 'is_primary' => true,
            'credentials' => ['webhook_secret' => $secret, 'webhook_verify_token' => 'verify-token'],
        ]);

        return [$organization, $connection, $secret];
    }

    /** @param array<string, mixed> $payload */
    private function postSigned(array $payload, string $secret): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call('POST', '/api/webhooks/whatsapp/manual', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WABIZ_SIGNATURE' => $signature,
        ], $raw);
    }
}
