<?php

namespace Tests\Feature;

use App\Contracts\AiOrchestrator as AiOrchestratorContract;
use App\Contracts\AiProviderInterface;
use App\DataTransferObjects\AI\AiProviderResponse;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\AiContextBuilder;
use App\Services\AI\AiResponseSafetyPolicy;
use App\Services\AI\DeterministicAiOrchestrator;
use App\Services\AI\DeterministicAiProvider;
use App\Services\AI\GeminiAiProvider;
use App\Services\AI\RegistrationHandoffResolver;
use App\Services\AI\RegistrationIntentClassifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Mockery;
use Tests\Support\DenyAiProvider;
use Tests\TestCase;

class AiOrchestratorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => '', 'services.gemini.model' => '']);
    }

    public function test_contracts_are_bound_to_deterministic_provider_without_external_ai(): void
    {
        $this->assertInstanceOf(AiOrchestratorContract::class, app(AiOrchestratorContract::class));
        $this->assertInstanceOf(GeminiAiProvider::class, app(AiProviderInterface::class));
        $this->assertInstanceOf(DenyAiProvider::class, app(AiProviderInterface::class));
        $this->app->instance(AiProviderInterface::class, app(DeterministicAiProvider::class));

        [$organization, $conversation, $message] = $this->scenario('provider-check', ['enabled' => true, 'institution_name' => 'Configured Campus']);
        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);

        $this->assertSame('RESPOND', $decision->responseAction);
        $this->assertStringContainsString('Configured Campus', (string) $decision->responseText);
        $this->assertSame('deterministic', $decision->metadata['provider']);
    }

    public function test_missing_or_disabled_settings_never_generate_an_automated_response(): void
    {
        [$organization, $conversation, $message] = $this->scenario('disabled', ['enabled' => false, 'institution_name' => 'Secretless Institute']);
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldNotReceive('generateResponse');
        $orchestrator = $this->orchestrator($provider);

        $before = $this->conversationSnapshot($conversation);
        $decision = $orchestrator->decide($organization, $conversation, $message);
        $this->assertSame('IGNORE', $decision->responseAction);
        $this->assertNull($decision->responseText);
        $this->assertSame('assistant_disabled', $decision->reason);
        $this->assertSame($before, $this->conversationSnapshot($conversation->fresh()));

        [$unconfiguredOrg, $unconfiguredConversation, $unconfiguredMessage] = $this->scenario('unconfigured', null);
        $unconfiguredDecision = $orchestrator->decide($unconfiguredOrg, $unconfiguredConversation, $unconfiguredMessage);
        $this->assertSame('IGNORE', $unconfiguredDecision->responseAction);
        $this->assertSame('settings_not_configured', $unconfiguredDecision->reason);
    }

    public function test_registration_classifier_handles_natural_wording_and_normalizes_whitespace(): void
    {
        $classifier = new RegistrationIntentClassifier;
        foreach ([
            'saya mau daftar', 'bagaimana cara daftar?', 'mau registrasi', 'pendaftaran bagaimana',
            'link pendaftaran', 'cara mendaftar', 'saya ingin mendaftar', 'info pendaftaran',
            'ingin masuk', 'mau jadi peserta', 'saya ingin menjadi santri', '  SAYA   MAU    DAFTAR!!!  ',
            'Saya mau mendaftarkan anak saya', 'Apa syarat pendaftaran?', 'Ada link pendaftaran?',
            'How do I enroll my child?', 'I want to sign up',
        ] as $wording) {
            $this->assertSame('REGISTRATION', $classifier->classify(new Message(['message_type' => 'text', 'body' => $wording])), $wording);
        }

        $this->assertSame('GENERAL', $classifier->classify(new Message(['message_type' => 'text', 'body' => 'Jam layanan hari ini?'])));
        $this->assertSame('GENERAL', $classifier->classify(new Message(['message_type' => 'text', 'body' => 'Saya hanya ingin tahu alamat sekolahnya'])));
        $this->assertSame('UNKNOWN', $classifier->classify(new Message(['message_type' => 'text', 'body' => '  '])));
        $this->assertSame('UNKNOWN', $classifier->classify(new Message(['message_type' => 'image', 'body' => 'image caption'])));
    }

    public function test_registration_disabled_never_returns_configured_url_or_claims_registration_is_open(): void
    {
        [$organization, $conversation, $message] = $this->scenario('reg-disabled', [
            'enabled' => true, 'registration_enabled' => false,
            'registration_url' => 'https://example.test/should-not-be-used',
        ], 'Saya mau daftar');

        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldNotReceive('generateResponse');
        $decision = $this->orchestrator($provider)->decide($organization, $conversation, $message);
        $this->assertSame('REGISTRATION', $decision->intent);
        $this->assertSame('RESPOND', $decision->responseAction);
        $this->assertStringContainsString('belum tersedia', $decision->responseText);
        $this->assertStringNotContainsString('https://example.test/should-not-be-used', $decision->responseText);
        $this->assertFalse($decision->handoffRequired);
    }

    public function test_registration_enabled_uses_only_configured_url_description_and_cs_phone(): void
    {
        [$organization, $conversation, $message] = $this->scenario('reg-url', [
            'enabled' => true, 'registration_enabled' => true,
            'registration_url' => 'https://apply.example.test/form',
            'registration_description' => 'Isi formulir resmi yang tersedia.',
            'registration_cs_phone' => '+6281234567890',
        ], 'Bagaimana pendaftaran?');

        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);
        $this->assertSame('RESPOND', $decision->responseAction);
        $this->assertStringContainsString('Isi formulir resmi yang tersedia.', $decision->responseText);
        $this->assertStringContainsString('https://apply.example.test/form', $decision->responseText);
        $this->assertStringContainsString('+6281234567890', $decision->responseText);
        $this->assertFalse($decision->handoffRequired);
    }

    public function test_registration_without_url_uses_description_or_safe_missing_information_response(): void
    {
        [$organization, $conversation, $message] = $this->scenario('reg-description', [
            'enabled' => true, 'registration_enabled' => true,
            'registration_description' => 'Pendaftaran dilakukan dengan mengisi formulir di kantor.',
        ], 'Saya ingin mendaftar');
        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);
        $this->assertSame('RESPOND', $decision->responseAction);
        $this->assertSame('Pendaftaran dilakukan dengan mengisi formulir di kantor.', $decision->responseText);
        $this->assertStringNotContainsString('http', $decision->responseText);

        [$orgMissing, $conversationMissing, $messageMissing] = $this->scenario('reg-missing', [
            'enabled' => true, 'registration_enabled' => true,
        ], 'Link pendaftaran?');
        $missingDecision = app(AiOrchestratorContract::class)->decide($orgMissing, $conversationMissing, $messageMissing);
        $this->assertSame('RESPOND', $missingDecision->responseAction);
        $this->assertStringContainsString('belum tersedia', $missingDecision->responseText);
        $this->assertStringNotContainsString('http', $missingDecision->responseText);
    }

    public function test_registration_cs_phone_returns_handoff_decision_without_fabricating_assignment(): void
    {
        [$organization, $conversation, $message] = $this->scenario('reg-handoff-unmapped', [
            'enabled' => true, 'registration_enabled' => true,
            'registration_cs_phone' => '+6281234567000',
        ], 'Info pendaftaran');

        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);
        $this->assertSame('HANDOFF', $decision->responseAction);
        $this->assertTrue($decision->handoffRequired);
        $this->assertNull($decision->handoffDepartmentId);
        $this->assertNull($decision->handoffCsMemberId);
        $this->assertSame('registration_contact_not_mapped_to_active_cs', $decision->reason);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->cs_member_id);
    }

    public function test_registration_cs_phone_maps_only_to_active_same_tenant_cs_member_and_department(): void
    {
        [$organization, $conversation, $message] = $this->scenario('reg-handoff-mapped', [
            'enabled' => true, 'registration_enabled' => true,
            'registration_cs_phone' => '+6281234567890',
        ], 'Saya ingin mendaftar');
        $user = User::forceCreate([
            'organization_id' => $organization->id, 'name' => 'CS Agent',
            'email' => 'cs-'.bin2hex(random_bytes(5)).'@example.test', 'password' => 'password', 'role' => 'staff',
        ]);
        $department = $organization->departments()->create(['name' => 'Admissions '.bin2hex(random_bytes(3)), 'active' => true]);
        $member = CsMember::forceCreate([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'department_id' => $department->id,
            'display_name' => 'Admission Agent', 'phone_number' => '0812-3456-7890', 'active' => true,
        ]);

        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);
        $this->assertSame('HANDOFF', $decision->responseAction);
        $this->assertSame($department->id, $decision->handoffDepartmentId);
        $this->assertSame($member->id, $decision->handoffCsMemberId);
        $this->assertTrue($decision->metadata['assignment_resolved']);
        $this->assertNull($conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
    }

    public function test_registration_department_selects_first_active_cs_deterministically(): void
    {
        [$organization, $conversation, $message] = $this->scenario('department-route', [
            'enabled' => true, 'registration_enabled' => true,
        ], 'Saya mau daftar');
        $department = $organization->departments()->create(['name' => 'Pendaftaran', 'active' => true]);
        $setting = $organization->aiAssistantSetting()->firstOrFail();
        $setting->forceFill(['registration_department_id' => $department->id])->save();
        $inactiveUser = User::forceCreate([
            'organization_id' => $organization->id, 'name' => 'Inactive',
            'email' => 'inactive-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'password', 'role' => 'staff',
        ]);
        $firstUser = User::forceCreate([
            'organization_id' => $organization->id, 'name' => 'First',
            'email' => 'first-'.bin2hex(random_bytes(4)).'@example.test', 'password' => 'password', 'role' => 'staff',
        ]);
        $first = CsMember::forceCreate([
            'organization_id' => $organization->id, 'user_id' => $firstUser->id, 'department_id' => $department->id,
            'display_name' => 'First CS', 'phone_number' => '+6281234567890', 'active' => true,
        ]);
        CsMember::forceCreate([
            'organization_id' => $organization->id, 'user_id' => $inactiveUser->id, 'department_id' => $department->id,
            'display_name' => 'Inactive CS', 'phone_number' => '+6281234567891', 'active' => false,
        ]);

        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);

        $this->assertSame('REGISTRATION', $decision->intent);
        $this->assertSame('HANDOFF', $decision->responseAction);
        $this->assertSame($department->id, $decision->handoffDepartmentId);
        $this->assertSame($first->id, $decision->handoffCsMemberId);
    }

    public function test_general_response_uses_configured_facts_and_missing_facts_are_not_invented(): void
    {
        [$organization, $conversation, $message] = $this->scenario('general-configured', [
            'enabled' => true, 'institution_name' => 'Configured Institute',
            'address' => 'Jl. Example 10', 'phone' => '+6221000000',
        ], 'Apa alamatnya?');
        $decision = app(AiOrchestratorContract::class)->decide($organization, $conversation, $message);
        $this->assertSame('GENERAL', $decision->intent);
        $this->assertSame('RESPOND', $decision->responseAction);
        $this->assertStringContainsString('Configured Institute', $decision->responseText);
        $this->assertStringContainsString('Jl. Example 10', $decision->responseText);
        $this->assertStringNotContainsString('alamat rekaan', $decision->responseText);

        [$emptyOrg, $emptyConversation, $emptyMessage] = $this->scenario('general-empty', ['enabled' => true], 'Apa alamatnya?');
        $emptyDecision = app(AiOrchestratorContract::class)->decide($emptyOrg, $emptyConversation, $emptyMessage);
        $this->assertSame('RESPOND', $emptyDecision->responseAction);
        $this->assertStringContainsString('belum tersedia', $emptyDecision->responseText);
        $this->assertStringNotContainsString('alamat', strtolower($emptyDecision->responseText));
    }

    public function test_context_is_tenant_scoped_contains_recent_history_and_excludes_secrets(): void
    {
        [$organization, $conversation, $incoming] = $this->scenario('context', [
            'enabled' => true, 'institution_name' => 'Only configured institution',
            'registration_enabled' => true, 'registration_description' => 'Configured registration detail',
        ], 'Current question');
        $client = Client::query()->findOrFail($conversation->client_id);
        $client->update(['email' => 'private-customer@example.test']);
        $whatsappSecret = 'whatsapp-secret-not-for-ai-'.bin2hex(random_bytes(8));
        $organization->whatsappConnections()->create([
            'provider' => 'internal_test', 'display_name' => 'No context', 'phone_number' => '+6281111111111',
            'enabled' => true, 'credentials' => ['webhook_secret' => $whatsappSecret, 'webhook_verify_token' => 'private-verification-token'],
        ]);
        $incoming->forceFill(['metadata' => ['api_key' => 'message-meta-secret'], 'external_message_id' => 'external-private-id'])->save();
        for ($i = 0; $i < 22; $i++) {
            $conversation->messages()->create([
                'direction' => $i === 0 ? 'inbound' : 'outbound',
                'sender_type' => $i === 0 ? 'customer' : 'human', 'message_type' => 'text',
                'body' => 'history-'.$i, 'sent_at' => now()->addSeconds($i),
            ]);
        }
        $context = app(AiContextBuilder::class)->build($organization, $conversation, $incoming, 'GENERAL');
        $encoded = json_encode($context->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('Only configured institution', $context->settings['institution_name']);
        $this->assertTrue($context->registration['registration_enabled']);
        $this->assertSame('Configured Customer', $context->client['name']);
        $this->assertSame('whatsapp', $context->conversation['channel']);
        $this->assertCount(20, $context->recentMessages);
        $this->assertSame('history-3', $context->recentMessages[0]['body']);
        $this->assertSame('Current question', $context->recentMessages[19]['body']);
        $this->assertStringNotContainsString($whatsappSecret, $encoded);
        $this->assertStringNotContainsString('private-verification-token', $encoded);
        $this->assertStringNotContainsString('private-customer@example.test', $encoded);
        $this->assertStringNotContainsString('message-meta-secret', $encoded);
        $this->assertStringNotContainsString('external-private-id', $encoded);

        [$otherOrganization, $otherConversation] = $this->scenario('other-tenant', ['enabled' => true]);
        try {
            app(AiContextBuilder::class)->build($otherOrganization, $conversation, $incoming, 'GENERAL');
            $this->fail('Cross-tenant context should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertNotSame($organization->id, $otherOrganization->id);
    }

    public function test_context_contains_configured_institution_fields_and_only_active_history_generation(): void
    {
        [$organization, $conversation, $first] = $this->scenario('generation-context', [
            'enabled' => true,
            'institution_name' => 'Institute One',
            'student_count' => 132,
            'caregiver_count' => 9,
            'teacher_count' => 17,
            'google_maps_url' => 'https://maps.example.test/institute-one',
            'additional_information' => 'Organization-only fact A',
            'registration_enabled' => true,
            'registration_url' => 'https://apply.example.test/org-one',
            'faqs' => [['question' => 'Q One', 'answer' => 'A One']],
        ], 'Old context');
        $secondOld = $conversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'human', 'message_type' => 'text', 'body' => 'Old answer',
        ]);
        $first->forceFill(['history_generation' => 1])->save();
        $conversation->forceFill(['history_generation' => 2])->save();
        $current = $conversation->messages()->create([
            'direction' => 'inbound', 'history_generation' => 2, 'sender_type' => 'customer', 'message_type' => 'text', 'body' => 'New generation only',
        ]);
        $secondOld->forceFill(['history_generation' => 1])->save();

        $context = app(AiContextBuilder::class)->build($organization, $conversation->fresh(), $current, 'GENERAL');
        $this->assertSame(132, $context->settings['student_count']);
        $this->assertSame(9, $context->settings['caregiver_count']);
        $this->assertSame(17, $context->settings['teacher_count']);
        $this->assertSame('https://maps.example.test/institute-one', $context->settings['google_maps_url']);
        $this->assertSame('https://apply.example.test/org-one', $context->registration['registration_url']);
        $this->assertSame('Q One', $context->settings['faqs'][0]['question']);
        $this->assertSame('A One', $context->settings['faqs'][0]['answer']);
        $history = array_column($context->recentMessages, 'body');
        $this->assertSame(['New generation only'], $history);

        [$otherOrganization, $otherConversation, $otherMessage] = $this->scenario('generation-other-tenant', [
            'enabled' => true,
            'institution_name' => 'Institute Two',
            'student_count' => 999,
            'registration_url' => 'https://apply.example.test/org-two',
        ]);
        $otherContext = app(AiContextBuilder::class)->build($otherOrganization, $otherConversation, $otherMessage, 'GENERAL');
        $this->assertSame('Institute Two', $otherContext->settings['institution_name']);
        $this->assertSame(999, $otherContext->settings['student_count']);
        $this->assertSame('https://apply.example.test/org-two', $otherContext->registration['registration_url']);
        $encodedA = json_encode($context->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Institute Two', $encodedA);
        $this->assertStringNotContainsString('https://apply.example.test/org-two', $encodedA);
    }

    public function test_general_classification_is_deterministic_and_decision_never_mutates_status_or_assignment(): void
    {
        [$organization, $conversation, $message] = $this->scenario('deterministic', ['enabled' => true], 'Where can I find more information?');
        $conversation->forceFill(['department_id' => null, 'cs_member_id' => null])->save();
        $before = $this->conversationSnapshot($conversation->fresh());
        $orchestrator = app(AiOrchestratorContract::class);
        $first = $orchestrator->decide($organization, $conversation, $message)->toArray();
        $second = $orchestrator->decide($organization, $conversation, $message)->toArray();

        $this->assertSame($first, $second);
        $this->assertSame($before, $this->conversationSnapshot($conversation->fresh()));
    }

    public function test_provider_cannot_invent_url_phone_registration_availability_or_assignment(): void
    {
        [$organization, $conversation, $message] = $this->scenario('provider-safety', [
            'enabled' => true,
            'institution_name' => 'Configured Institute',
            'registration_enabled' => false,
        ], 'What services are available?');
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('generateResponse')->once()->andReturn(new AiProviderResponse(
            responseText: 'Register now at https://invented.example.test and call +628999999999. I assigned this to CS 42.',
            confidence: 0.99,
            metadata: ['provider' => 'fake-test-provider'],
        ));
        $orchestrator = $this->orchestrator($provider);

        $decision = $orchestrator->decide($organization, $conversation, $message);
        $this->assertSame('RESPOND', $decision->responseAction);
        $this->assertStringContainsString('Configured Institute', $decision->responseText);
        $this->assertStringNotContainsString('invented.example.test', $decision->responseText);
        $this->assertStringNotContainsString('+628999999999', $decision->responseText);
        $this->assertFalse($decision->handoffRequired);
        $this->assertNull($decision->handoffCsMemberId);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->cs_member_id);
    }

    public function test_context_builder_rejects_message_from_another_conversation(): void
    {
        [$organization, $conversation, $message] = $this->scenario('scope-a', ['enabled' => true]);
        [, $otherConversation] = $this->scenario('scope-b', ['enabled' => true]);
        try {
            app(AiContextBuilder::class)->build($organization, $otherConversation, $message, 'GENERAL');
            $this->fail('A message from another conversation should be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    /** @param array<string, mixed>|null $settings @return array{Organization, Conversation, Message} */
    private function scenario(string $suffix, ?array $settings, string $body = 'General question'): array
    {
        $organization = Organization::query()->create([
            'name' => 'Orchestrator '.$suffix,
            'slug' => 'orchestrator-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($suffix)).'-'.bin2hex(random_bytes(4)),
        ]);
        if ($settings !== null) {
            $organization->aiAssistantSetting()->create($settings);
        }
        $client = $organization->clients()->create([
            'name' => 'Configured Customer', 'phone_number' => '+6281234567890', 'email' => null, 'status' => 'new',
        ]);
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id, 'status' => 'AI_ACTIVE', 'channel' => 'whatsapp', 'started_at' => now(),
        ]);
        $message = $conversation->messages()->create([
            'direction' => 'inbound', 'sender_type' => 'customer', 'message_type' => 'text',
            'body' => $body, 'sent_at' => now(),
        ]);

        return [$organization, $conversation, $message];
    }

    private function orchestrator(AiProviderInterface $provider): DeterministicAiOrchestrator
    {
        return new DeterministicAiOrchestrator(
            new RegistrationIntentClassifier,
            new AiContextBuilder,
            $provider,
            app(RegistrationHandoffResolver::class),
            app(AiResponseSafetyPolicy::class),
            app(DeterministicAiProvider::class),
        );
    }

    /** @return array<string, mixed> */
    private function conversationSnapshot(Conversation $conversation): array
    {
        return $conversation->only(['status', 'department_id', 'cs_member_id', 'assigned_at', 'last_message_at']);
    }
}


