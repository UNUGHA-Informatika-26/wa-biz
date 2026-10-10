<?php

namespace Tests\Feature;

use App\Contracts\AiOrchestrator;
use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\AI\AiDecision;
use App\DataTransferObjects\AI\AiMessageProcessingStatus;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Jobs\SendAiConversationMessage;
use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AI\AiMessageProcessor;
use App\Services\WhatsApp\WhatsAppMessageSender;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AiMessageProcessorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => '', 'services.gemini.model' => '']);
        config(['app.key' => 'base64:'.base64_encode('01234567890123456789012345678901')]);
        Http::preventStrayRequests();
        Queue::fake([SendAiConversationMessage::class]);
    }

    public function test_inbound_message_is_processed_and_stored_as_unsent_ai_message(): void
    {
        [$organization, $conversation, $incoming] = $this->scenario('stored', [
            'enabled' => true, 'institution_name' => 'Configured Institute', 'phone' => '+6221000000',
        ]);

        $result = app(AiMessageProcessor::class)->process($incoming);
        $outbound = Message::query()->where('ai_source_message_id', $incoming->id)->firstOrFail();

        $this->assertSame(AiMessageProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('outbound', $outbound->direction);
        $this->assertSame('ai', $outbound->sender_type);
        $this->assertSame('text', $outbound->message_type);
        $this->assertNull($outbound->external_message_id);
        $this->assertNull($outbound->sent_at);
        $this->assertStringContainsString('Configured Institute', $outbound->body);
        $this->assertSame('deterministic', $outbound->metadata['provider']);
        $this->assertSame('GENERAL', $outbound->metadata['intent']);
        $this->assertSame('ai_message_processor', $outbound->metadata['processing_source']);
        $this->assertNotNull($result->outboundMessageId);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
        $this->assertTrue($conversation->fresh()->last_message_at->equalTo($outbound->created_at));
        $this->assertSame($organization->id, $conversation->fresh()->organization_id);
        $this->assertSame('pending', $outbound->delivery_status);
        Queue::assertPushed(SendAiConversationMessage::class, 1);
    }

    public function test_ai_job_sends_persisted_reply_through_tenant_connection_and_is_idempotent(): void
    {
        [$organization, $conversation, $incoming] = $this->scenario('delivery-success', [
            'enabled' => true, 'institution_name' => 'Configured Institute',
        ]);
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'ai_delivery_test', 'display_name' => 'AI test connection',
            'phone_number' => '+6281111111111', 'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
        $provider = new AiDeliveryFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));

        $result = app(AiMessageProcessor::class)->process($incoming);
        $outbound = Message::query()->where('ai_source_message_id', $incoming->id)->firstOrFail();
        $this->assertSame(AiMessageProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('pending', $outbound->delivery_status);
        Queue::assertPushed(SendAiConversationMessage::class, fn (SendAiConversationMessage $job): bool => $job->messageId === $outbound->id);

        $job = new SendAiConversationMessage((int) $outbound->id);
        $job->handle(app(WhatsAppMessageSender::class));
        $job->handle(app(WhatsAppMessageSender::class));

        $this->assertSame(1, $provider->sendCount);
        $this->assertSame($connection->id, $provider->lastConnection?->id);
        $this->assertSame('+6281234567890', $provider->lastRequest?->recipientPhone);
        $this->assertSame('sent', $outbound->fresh()->delivery_status);
        $this->assertSame('ai-fake-id-1', $outbound->fresh()->external_message_id);
        $this->assertNotNull($outbound->fresh()->sent_at);
        Http::assertNothingSent();
    }

    public function test_ai_job_suppresses_human_resolved_and_old_generation_responses(): void
    {
        $provider = new AiDeliveryFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
        foreach ([
            ['human-waiting', 'WAITING_HUMAN', false],
            ['human-active', 'HUMAN_ACTIVE', false],
            ['resolved', 'RESOLVED', false],
            ['generation-reset', 'AI_ACTIVE', true],
        ] as [$suffix, $status, $reset]) {
            [$organization, $conversation, $incoming] = $this->scenario($suffix, ['enabled' => true]);
            $connection = $organization->whatsappConnections()->create([
                'provider' => 'ai_delivery_test', 'display_name' => 'AI test connection',
                'phone_number' => '+6281111111111', 'enabled' => true, 'status' => 'connected', 'is_primary' => true,
            ]);
            $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
            app(AiMessageProcessor::class)->process($incoming);
            $outbound = Message::query()->where('ai_source_message_id', $incoming->id)->firstOrFail();
            $conversation->forceFill([
                'status' => $status,
                'history_generation' => (int) $conversation->history_generation + ($reset ? 1 : 0),
            ])->save();

            (new SendAiConversationMessage((int) $outbound->id))->handle(app(WhatsAppMessageSender::class));

            $this->assertSame('failed', $outbound->fresh()->delivery_status, $suffix);
            $this->assertSame('suppressed', $outbound->fresh()->metadata['delivery_state'], $suffix);
            $this->assertNull($outbound->fresh()->external_message_id, $suffix);
        }
        $this->assertSame(0, $provider->sendCount);
        Http::assertNothingSent();
    }

    public function test_ai_known_and_ambiguous_provider_failures_are_persisted_without_retry(): void
    {
        foreach ([['known-failure', 'invalid_message_request', 'failed'], ['ambiguous', 'transport_error', 'unknown']] as [$suffix, $failure, $expected]) {
            [$organization, $conversation, $incoming] = $this->scenario($suffix, ['enabled' => true]);
            $connection = $organization->whatsappConnections()->create([
                'provider' => 'ai_delivery_test', 'display_name' => 'AI test connection',
                'phone_number' => '+6281111111111', 'enabled' => true, 'status' => 'connected', 'is_primary' => true,
            ]);
            $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
            $provider = new AiDeliveryFakeProvider($failure);
            $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
            app(AiMessageProcessor::class)->process($incoming);
            $outbound = Message::query()->where('ai_source_message_id', $incoming->id)->firstOrFail();
            $job = new SendAiConversationMessage((int) $outbound->id);
            $job->handle(app(WhatsAppMessageSender::class));
            $job->handle(app(WhatsAppMessageSender::class));

            $this->assertSame($expected, $outbound->fresh()->delivery_status, $suffix);
            $this->assertSame(1, $provider->sendCount, $suffix);
            $this->assertNull($outbound->fresh()->external_message_id, $suffix);
        }
        Http::assertNothingSent();
    }

    public function test_missing_or_disabled_ai_settings_are_ignored_without_orchestrator_call(): void
    {
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldNotReceive('decide');
        $this->app->instance(AiOrchestrator::class, $orchestrator);

        [, , $disabled] = $this->scenario('disabled', ['enabled' => false]);
        $disabledResult = app(AiMessageProcessor::class)->process($disabled);
        [, , $unconfigured] = $this->scenario('unconfigured', null);
        $unconfiguredResult = app(AiMessageProcessor::class)->process($unconfigured);

        $this->assertSame(AiMessageProcessingStatus::IGNORED, $disabledResult->status);
        $this->assertSame('assistant_disabled', $disabledResult->reason);
        $this->assertSame(AiMessageProcessingStatus::IGNORED, $unconfiguredResult->status);
        $this->assertSame('settings_not_configured', $unconfiguredResult->reason);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_only_ai_active_conversations_are_eligible(): void
    {
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldNotReceive('decide');
        $this->app->instance(AiOrchestrator::class, $orchestrator);

        foreach (['ARCHIVED', 'HUMAN_ACTIVE', 'RESOLVED', 'WAITING_HUMAN', 'TRANSFERRED_TO_HUMAN'] as $status) {
            [, , $incoming] = $this->scenario('status-'.$status, ['enabled' => true], $status);
            $incoming->conversation->forceFill(['status' => $status])->save();
            $result = app(AiMessageProcessor::class)->process($incoming);
            $this->assertSame(AiMessageProcessingStatus::IGNORED, $result->status, $status);
            $this->assertNull($result->outboundMessageId, $status);
        }

        $this->assertSame(5, Message::query()->where('direction', 'inbound')->count());
    }

    public function test_ai_decision_started_before_human_takeover_cannot_persist_a_stale_reply(): void
    {
        [, $conversation, $incoming] = $this->scenario('takeover-race', ['enabled' => true]);
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldReceive('decide')->once()->andReturnUsing(function () use ($conversation): AiDecision {
            $conversation->forceFill(['status' => 'HUMAN_ACTIVE'])->save();

            return new AiDecision('GENERAL', 'RESPOND', 'Stale AI response', false, null, null, 'test', 0.9);
        });
        $this->app->instance(AiOrchestrator::class, $orchestrator);

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::IGNORED, $result->status);
        $this->assertSame('conversation_not_ai_active', $result->reason);
        $this->assertDatabaseMissing('messages', ['ai_source_message_id' => $incoming->id]);
        $this->assertSame('HUMAN_ACTIVE', $conversation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_outbound_or_non_customer_message_is_ignored(): void
    {
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldNotReceive('decide');
        $this->app->instance(AiOrchestrator::class, $orchestrator);
        [, $conversation, $incoming] = $this->scenario('direction', ['enabled' => true]);

        $outbound = $conversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text', 'body' => 'not inbound',
        ]);
        $this->assertSame(AiMessageProcessingStatus::IGNORED, app(AiMessageProcessor::class)->process($outbound)->status);
        $incoming->forceFill(['sender_type' => 'human'])->save();
        $this->assertSame(AiMessageProcessingStatus::IGNORED, app(AiMessageProcessor::class)->process($incoming)->status);
        $this->assertSame(2, Message::query()->count());
    }

    public function test_ignore_decision_creates_no_outbound_message(): void
    {
        [, , $incoming] = $this->scenario('ignore', ['enabled' => true]);
        $this->bindDecision(new AiDecision('UNKNOWN', 'IGNORE', null, false, null, null, 'unsupported', null));

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::IGNORED, $result->status);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_handoff_result_is_executed_and_unassigned_target_is_reported_safely(): void
    {
        [, $conversation, $incoming] = $this->scenario('handoff', [
            'enabled' => true, 'registration_enabled' => true, 'registration_cs_phone' => '+6281234567890',
        ], 'Saya ingin mendaftar');

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::HANDOFF_REQUIRED, $result->status);
        $this->assertSame('no_active_registration_cs', $result->reason);
        $this->assertTrue($result->decision?->handoffRequired);
        $this->assertSame('WAITING_HUMAN', $conversation->fresh()->status);
        $this->assertSame('registration_contact_not_mapped_to_active_cs', $conversation->fresh()->handoff_reason);
        $this->assertNull($conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_registration_without_cs_stops_ai_and_later_inbound_is_still_stored(): void
    {
        [, $conversation, $firstInbound] = $this->scenario('manual-registration-review', [
            'enabled' => true,
            'registration_enabled' => true,
            'registration_cs_phone' => '+6281234567890',
        ], 'Saya ingin daftar');

        $firstResult = app(AiMessageProcessor::class)->process($firstInbound);
        $this->assertSame(AiMessageProcessingStatus::HANDOFF_REQUIRED, $firstResult->status);
        $this->assertSame('WAITING_HUMAN', $conversation->fresh()->status);

        $nextInbound = $conversation->messages()->create([
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'body' => 'Ada yang bisa membantu?',
            'history_generation' => (int) $conversation->fresh()->history_generation,
        ]);
        $nextResult = app(AiMessageProcessor::class)->process($nextInbound);

        $this->assertSame(AiMessageProcessingStatus::IGNORED, $nextResult->status);
        $this->assertSame('waiting_for_human', $nextResult->reason);
        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(0, Message::query()->where('direction', 'outbound')->count());
    }

    public function test_registration_handoff_result_uses_configured_active_cs_and_transfers_conversation(): void
    {
        [$organization, $conversation, $incoming] = $this->scenario('handoff-resolved', [
            'enabled' => true, 'registration_enabled' => true, 'registration_cs_phone' => '+6281234567890',
        ], 'Saya ingin mendaftar');
        $user = User::forceCreate([
            'organization_id' => $organization->id, 'name' => 'Admission CS',
            'email' => 'handoff-'.bin2hex(random_bytes(5)).'@example.test', 'password' => 'password', 'role' => 'staff',
        ]);
        $department = $organization->departments()->create(['name' => 'Admissions', 'active' => true]);
        $member = CsMember::forceCreate([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'department_id' => $department->id,
            'display_name' => 'Admission CS', 'phone_number' => '+6281234567890', 'active' => true,
        ]);

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::HANDOFF_REQUIRED, $result->status);
        $this->assertSame('handoff_required', $result->reason);
        $this->assertSame('TRANSFERRED_TO_HUMAN', $conversation->fresh()->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertSame($member->id, $conversation->fresh()->cs_member_id);
        $this->assertNotNull($conversation->fresh()->assigned_at);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_empty_response_is_not_persisted(): void
    {
        [, , $incoming] = $this->scenario('empty', ['enabled' => true]);
        $this->bindDecision(new AiDecision('GENERAL', 'RESPOND', " \n ", false, null, null, 'empty', 1.0));

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::IGNORED, $result->status);
        $this->assertSame('empty_response', $result->reason);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_unsafe_credentials_ids_urls_phones_and_registration_claims_are_rejected(): void
    {
        $unsafeResponses = [
            'API key: AIza12345678901234567890abcde',
            'Conversation ID: 987654',
            'Visit https://invented.example.test now',
            'Visit invented.example now',
            'Call +628999999999 today',
            'Daftar sekarang, pendaftaran tersedia.',
        ];

        foreach ($unsafeResponses as $index => $response) {
            [, , $incoming] = $this->scenario('unsafe-'.$index, [
                'enabled' => true, 'registration_enabled' => false,
            ]);
            $this->bindDecision(new AiDecision('GENERAL', 'RESPOND', $response, false, null, null, 'test', 1.0));
            $result = app(AiMessageProcessor::class)->process($incoming);
            $this->assertSame(AiMessageProcessingStatus::FAILED, $result->status, $response);
            $this->assertSame('response_failed_safety_validation', $result->reason);
            $this->assertDatabaseMissing('messages', ['ai_source_message_id' => $incoming->id]);
        }
    }

    public function test_registration_disabled_decision_remains_explicitly_unavailable_and_safe(): void
    {
        [, , $incoming] = $this->scenario('registration-disabled', [
            'enabled' => true, 'registration_enabled' => false,
            'registration_url' => 'https://configured-but-disabled.example.test',
        ], 'Saya mau daftar');

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::PROCESSED, $result->status);
        $outbound = Message::query()->where('ai_source_message_id', $incoming->id)->firstOrFail();
        $this->assertStringContainsString('belum tersedia', $outbound->body);
        $this->assertStringNotContainsString('tersedia untuk pendaftaran', $outbound->body);
        $this->assertStringNotContainsString('configured-but-disabled', $outbound->body);
    }

    public function test_duplicate_processor_calls_create_one_ai_message_and_skip_second_provider_decision(): void
    {
        [, $conversation, $incoming] = $this->scenario('duplicate', ['enabled' => true]);
        $decision = new AiDecision('GENERAL', 'RESPOND', 'Safe deterministic response.', false, null, null, 'ok', 0.9, ['provider' => 'deterministic']);
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldReceive('decide')->once()->andReturn($decision);
        $this->app->instance(AiOrchestrator::class, $orchestrator);

        $processor = app(AiMessageProcessor::class);
        $first = $processor->process($incoming);
        $second = $processor->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::PROCESSED, $first->status);
        $this->assertSame(AiMessageProcessingStatus::ALREADY_PROCESSED, $second->status);
        $this->assertSame(1, $conversation->messages()->where('sender_type', 'ai')->count());
        $this->assertCount(1, $incoming->fresh()->aiResponses);
    }

    public function test_database_unique_constraint_is_the_concurrent_duplicate_guard(): void
    {
        [, $conversation, $incoming] = $this->scenario('unique-guard', ['enabled' => true]);
        $outbound = $conversation->messages()->make([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text', 'body' => 'first',
        ]);
        $outbound->forceFill(['ai_source_message_id' => $incoming->id]);
        $conversation->messages()->save($outbound);

        $this->expectException(QueryException::class);
        $duplicate = $conversation->messages()->make([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text', 'body' => 'second',
        ]);
        $duplicate->forceFill(['ai_source_message_id' => $incoming->id]);
        $conversation->messages()->save($duplicate);
    }

    public function test_gemini_http_failure_uses_deterministic_provider_without_external_network(): void
    {
        [, , $incoming] = $this->scenario('gemini-fallback', [
            'enabled' => true, 'institution_name' => 'Fallback Institute',
        ]);
        config([
            'services.gemini.api_key' => 'unit-test-api-key-not-a-secret',
            'services.gemini.model' => 'gemini-test-model',
            'services.gemini.base_url' => 'https://generativelanguage.example.test/v1beta',
        ]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'safe fake failure']], 503)]);

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('deterministic', Message::query()->where('ai_source_message_id', $incoming->id)->firstOrFail()->metadata['provider']);
        Http::assertSentCount(1);
    }

    public function test_cross_tenant_client_reference_is_rejected(): void
    {
        $organization = $this->organization('tenant-a');
        $otherOrganization = $this->organization('tenant-b');
        $otherClient = $otherOrganization->clients()->create([
            'name' => 'Other tenant client', 'phone_number' => '+6281234567000', 'status' => 'new',
        ]);
        $conversation = $organization->conversations()->create([
            'client_id' => $otherClient->id, 'status' => 'AI_ACTIVE', 'channel' => 'whatsapp', 'started_at' => now(),
        ]);
        $incoming = $conversation->messages()->create([
            'direction' => 'inbound', 'sender_type' => 'customer', 'message_type' => 'text', 'body' => 'Hello',
        ]);
        $organization->aiAssistantSetting()->create(['enabled' => true]);
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldNotReceive('decide');
        $this->app->instance(AiOrchestrator::class, $orchestrator);

        $result = app(AiMessageProcessor::class)->process($incoming);

        $this->assertSame(AiMessageProcessingStatus::IGNORED, $result->status);
        $this->assertSame('organization_scope_invalid', $result->reason);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_signed_webhook_runs_processor_after_persisting_inbound_without_sending_whatsapp(): void
    {
        $organization = $this->organization('webhook-pipeline');
        $organization->aiAssistantSetting()->create(['enabled' => true, 'institution_name' => 'Webhook Institute']);
        $secret = bin2hex(random_bytes(32));
        $organization->whatsappConnections()->create([
            'provider' => 'manual', 'display_name' => 'Pipeline Test', 'phone_number' => '+6281111111111',
            'phone_number_id' => 'pipeline-phone-id', 'enabled' => true, 'is_primary' => true,
            'credentials' => ['webhook_secret' => $secret, 'webhook_verify_token' => 'verify-test'],
        ]);
        $payload = [
            'phone_number_id' => 'pipeline-phone-id',
            'messages' => [[
                'id' => 'pipeline-event-1', 'from' => '081234567890', 'type' => 'text',
                'text' => ['body' => 'Informasi apa yang tersedia?'],
            ]],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

        $this->call('POST', '/api/webhooks/whatsapp/manual', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WABIZ_SIGNATURE' => $signature,
        ], $raw)->assertOk()->assertJsonPath('processed', 1)->assertJsonPath('duplicates', 0);

        $inbound = Message::query()->where('external_message_id', 'pipeline-event-1')->firstOrFail();
        $outbound = Message::query()->where('ai_source_message_id', $inbound->id)->firstOrFail();
        $this->assertSame('inbound', $inbound->direction);
        $this->assertSame('outbound', $outbound->direction);
        $this->assertNull($outbound->sent_at);
        $this->assertNull($outbound->external_message_id);
        $this->assertStringContainsString('Webhook Institute', $outbound->body);

        $this->call('POST', '/api/webhooks/whatsapp/manual', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WABIZ_SIGNATURE' => $signature,
        ], $raw)->assertOk()->assertJsonPath('processed', 0)->assertJsonPath('duplicates', 1);
        $this->assertDatabaseCount('messages', 2);
        Http::assertNothingSent();
    }

    public function test_unpersisted_and_missing_messages_are_ignored(): void
    {
        $unpersisted = new Message(['direction' => 'inbound', 'sender_type' => 'customer']);
        $this->assertSame(AiMessageProcessingStatus::IGNORED, app(AiMessageProcessor::class)->process($unpersisted)->status);
        $missing = new Message;
        $missing->setAttribute($missing->getKeyName(), 987654321);
        $missing->exists = true;
        $this->assertSame(AiMessageProcessingStatus::IGNORED, app(AiMessageProcessor::class)->process($missing)->status);
    }

    /** @param array<string, mixed>|null $settings @return array{Organization, Conversation, Message} */
    private function scenario(string $suffix, ?array $settings, string $body = 'What services do you provide?'): array
    {
        $organization = $this->organization($suffix);
        if ($settings !== null) {
            $organization->aiAssistantSetting()->create($settings);
        }
        $client = $organization->clients()->create([
            'name' => 'Processor Customer', 'phone_number' => '+6281234567890', 'status' => 'new',
        ]);
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id, 'status' => 'AI_ACTIVE', 'channel' => 'whatsapp', 'started_at' => now(),
        ]);
        $incoming = $conversation->messages()->create([
            'direction' => 'inbound', 'sender_type' => 'customer', 'message_type' => 'text', 'body' => $body, 'sent_at' => now(),
        ]);

        return [$organization, $conversation, $incoming];
    }

    private function organization(string $suffix): Organization
    {
        return Organization::query()->create([
            'name' => 'Processor '.$suffix,
            'slug' => 'processor-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($suffix)).'-'.bin2hex(random_bytes(4)),
        ]);
    }

    private function bindDecision(AiDecision $decision): void
    {
        $orchestrator = Mockery::mock(AiOrchestrator::class);
        $orchestrator->shouldReceive('decide')->once()->andReturn($decision);
        $this->app->instance(AiOrchestrator::class, $orchestrator);
    }
}

class AiDeliveryFakeProvider implements WhatsAppProviderInterface
{
    public int $sendCount = 0;

    public ?WhatsAppSendTextRequest $lastRequest = null;

    public ?WhatsAppConnection $lastConnection = null;

    public function __construct(private readonly ?string $failureKind = null) {}

    public function identifier(): string
    {
        return 'ai_delivery_test';
    }

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        return new WhatsAppConnectionVerificationResult(true, $this->identifier(), 'connected');
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        $this->sendCount++;
        $this->lastConnection = $connection;
        $this->lastRequest = $request;
        if ($this->failureKind !== null) {
            throw new WhatsAppProviderException($this->failureKind);
        }

        return new WhatsAppSendResult(true, $this->identifier(), 'ai-fake-id-'.$this->sendCount, 'accepted');
    }
}
