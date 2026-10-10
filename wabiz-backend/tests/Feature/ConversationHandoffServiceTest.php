<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\AI\AiDecision;
use App\DataTransferObjects\AI\ConversationHandoffStatus;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Jobs\SendConversationHandoffNotification;
use App\Models\AiAssistantSetting;
use App\Models\Conversation;
use App\Models\ConversationHandoffNotification;
use App\Models\CsMember;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AI\ConversationHandoffService;
use App\Services\AI\RegistrationHandoffResolver;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationHandoffServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode('01234567890123456789012345678901')]);
        Http::preventStrayRequests();
    }

    public function test_ai_active_conversation_transfers_to_active_department(): void
    {
        [$organization, $conversation] = $this->scenario('department');
        $department = $this->department($organization, 'Admissions');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame('TRANSFERRED_TO_HUMAN', $conversation->fresh()->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
        $this->assertNotNull($conversation->fresh()->assigned_at);
    }

    public function test_cs_target_infers_its_active_department(): void
    {
        [$organization, $conversation] = $this->scenario('cs-only');
        $department = $this->department($organization, 'Support');
        $member = $this->member($organization, $department, 'Support CS');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision(null, $member->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertSame($member->id, $conversation->fresh()->cs_member_id);
    }

    public function test_matching_department_and_cs_assignment_succeeds(): void
    {
        [$organization, $conversation] = $this->scenario('matched');
        $department = $this->department($organization, 'Matched');
        $member = $this->member($organization, $department, 'Matched CS');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id, $member->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame([$department->id, $member->id], [$conversation->fresh()->department_id, $conversation->fresh()->cs_member_id]);
    }

    public function test_cross_organization_cs_is_rejected(): void
    {
        [$organization, $conversation] = $this->scenario('tenant-a-cs');
        [$otherOrganization] = $this->scenario('tenant-b-cs');
        $member = $this->member($otherOrganization, null, 'Foreign CS');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision(null, $member->id));

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->cs_member_id);
        $this->assertNotSame($organization->id, $otherOrganization->id);
    }

    public function test_cross_organization_department_is_rejected(): void
    {
        [, $conversation] = $this->scenario('tenant-a-dept');
        [$otherOrganization] = $this->scenario('tenant-b-dept');
        $department = $this->department($otherOrganization, 'Foreign department');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id));

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->department_id);
    }

    public function test_inactive_cs_is_rejected(): void
    {
        [$organization, $conversation] = $this->scenario('inactive-cs');
        $member = $this->member($organization, null, 'Inactive CS', false);

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision(null, $member->id));

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
    }

    public function test_inactive_department_is_rejected(): void
    {
        [$organization, $conversation] = $this->scenario('inactive-dept');
        $department = $this->department($organization, 'Inactive', false);

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id));

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
    }

    public function test_cs_department_mismatch_is_rejected(): void
    {
        [$organization, $conversation] = $this->scenario('mismatch');
        $departmentA = $this->department($organization, 'Department A');
        $departmentB = $this->department($organization, 'Department B');
        $member = $this->member($organization, $departmentB, 'Department B CS');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($departmentA->id, $member->id));

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertNull($conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
    }

    public function test_cs_with_inactive_linked_department_is_rejected(): void
    {
        [$organization, $conversation] = $this->scenario('cs-inactive-department');
        $department = $this->department($organization, 'Inactive linked department', false);
        $member = $this->member($organization, $department, 'CS with inactive department');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision(null, $member->id));

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->department_id);
    }

    public function test_department_can_be_set_for_cs_without_linked_department_per_existing_assignment_rule(): void
    {
        [$organization, $conversation] = $this->scenario('unlinked-cs');
        $department = $this->department($organization, 'Explicit department');
        $member = $this->member($organization, null, 'Unlinked CS');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id, $member->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertSame($member->id, $conversation->fresh()->cs_member_id);
    }

    public function test_absent_or_ambiguous_targets_are_never_guessed(): void
    {
        [, $none] = $this->scenario('no-target');
        $noneResult = app(ConversationHandoffService::class)->execute($none, $this->decision());
        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $noneResult->status);
        $this->assertSame('AI_ACTIVE', $none->fresh()->status);

        [$organization, $ambiguous] = $this->scenario('ambiguous-target');
        $this->department($organization, 'A');
        $this->department($organization, 'B');
        $this->member($organization, null, 'CS A');
        $this->member($organization, null, 'CS B');
        $ambiguousResult = app(ConversationHandoffService::class)->execute($ambiguous, $this->decision());
        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $ambiguousResult->status);
        $this->assertNull($ambiguous->fresh()->department_id);
        $this->assertNull($ambiguous->fresh()->cs_member_id);
    }

    public function test_only_unambiguous_active_fallback_target_is_used(): void
    {
        [$organization, $conversation] = $this->scenario('fallback-one-cs');
        $department = $this->department($organization, 'Only department');
        $member = $this->member($organization, $department, 'Only CS');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision());

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertSame($member->id, $conversation->fresh()->cs_member_id);
    }

    public function test_single_active_department_without_cs_is_safe_fallback(): void
    {
        [$organization, $conversation] = $this->scenario('fallback-one-dept');
        $department = $this->department($organization, 'Only department');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision());

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
    }

    public function test_registration_handoff_resolver_target_is_reused(): void
    {
        [$organization, $conversation] = $this->scenario('registration-resolver');
        $department = $this->department($organization, 'Admissions');
        $member = $this->member($organization, $department, 'Admissions CS', phone: '+6281234567890');
        $resolved = app(RegistrationHandoffResolver::class)->resolve($organization->id, '+6281234567890');
        $this->assertTrue($resolved['mapped']);

        $result = app(ConversationHandoffService::class)->execute(
            $conversation,
            $this->decision($resolved['department_id'], $resolved['cs_member_id']),
        );

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertSame($member->id, $conversation->fresh()->cs_member_id);
    }

    public function test_registration_resolver_miss_does_not_fallback_to_unrelated_cs(): void
    {
        [$organization, $conversation] = $this->scenario('registration-unmapped');
        $department = $this->department($organization, 'Unrelated department');
        $member = $this->member($organization, $department, 'Unrelated CS');
        $unmapped = app(RegistrationHandoffResolver::class)->resolve($organization->id, '+6281999999999');
        $this->assertFalse($unmapped['mapped']);

        $result = app(ConversationHandoffService::class)->execute(
            $conversation,
            new AiDecision('REGISTRATION', 'HANDOFF', null, true, $unmapped['department_id'], $unmapped['cs_member_id'], 'registration_requires_cs'),
        );

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertNull($conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
        $this->assertSame('WAITING_HUMAN', $conversation->fresh()->status);
        $this->assertSame('registration_requires_cs', $conversation->fresh()->handoff_reason);
        $this->assertNotNull($conversation->fresh()->handoff_requested_at);
        $this->assertDatabaseHas('cs_members', ['id' => $member->id]);
    }

    public function test_human_active_resolved_and_archived_states_are_rejected_without_mutation(): void
    {
        foreach (['HUMAN_ACTIVE', 'RESOLVED', 'ARCHIVED'] as $status) {
            [$organization, $conversation] = $this->scenario('closed-'.$status, $status);
            $department = $this->department($organization, 'Target '.$status);
            $before = $conversation->fresh()->only(['status', 'department_id', 'cs_member_id', 'assigned_at']);

            $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id));

            $this->assertSame(ConversationHandoffStatus::REJECTED, $result->status, $status);
            $this->assertSame($before, $conversation->fresh()->only(['status', 'department_id', 'cs_member_id', 'assigned_at']), $status);
        }
    }

    public function test_waiting_human_can_complete_assignment_without_reopening_ai(): void
    {
        [$organization, $conversation] = $this->scenario('waiting-human', 'WAITING_HUMAN');
        $department = $this->department($organization, 'Waiting target');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame('TRANSFERRED_TO_HUMAN', $conversation->fresh()->status);
    }

    public function test_waiting_human_without_valid_target_remains_unchanged(): void
    {
        [, $conversation] = $this->scenario('waiting-unassigned', 'WAITING_HUMAN');

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision());

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertSame('WAITING_HUMAN', $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->assigned_at);
    }

    public function test_same_duplicate_handoff_is_idempotent_and_different_target_is_rejected(): void
    {
        [$organization, $conversation] = $this->scenario('idempotent');
        $departmentA = $this->department($organization, 'Target A');
        $departmentB = $this->department($organization, 'Target B');
        $service = app(ConversationHandoffService::class);

        $first = $service->execute($conversation, $this->decision($departmentA->id));
        $assignedAt = $conversation->fresh()->assigned_at;
        $same = $service->execute($conversation, $this->decision($departmentA->id));
        $different = $service->execute($conversation, $this->decision($departmentB->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $first->status);
        $this->assertSame(ConversationHandoffStatus::ALREADY_HANDOFF, $same->status);
        $this->assertSame(ConversationHandoffStatus::REJECTED, $different->status);
        $this->assertSame($departmentA->id, $conversation->fresh()->department_id);
        $this->assertTrue($assignedAt->equalTo($conversation->fresh()->assigned_at));
    }

    public function test_existing_different_assignment_is_not_silently_replaced(): void
    {
        [$organization, $conversation] = $this->scenario('existing-assignment');
        $departmentA = $this->department($organization, 'Existing target');
        $departmentB = $this->department($organization, 'Requested target');
        $conversation->forceFill(['department_id' => $departmentA->id])->save();

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($departmentB->id));

        $this->assertSame(ConversationHandoffStatus::REJECTED, $result->status);
        $this->assertSame($departmentA->id, $conversation->fresh()->department_id);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
    }

    public function test_inactive_members_do_not_count_toward_unique_fallback(): void
    {
        [$organization, $conversation] = $this->scenario('inactive-fallback');
        $department = $this->department($organization, 'Fallback department');
        $this->member($organization, $department, 'Inactive fallback CS', false);

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision());

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertSame($department->id, $conversation->fresh()->department_id);
        $this->assertNull($conversation->fresh()->cs_member_id);
    }

    public function test_handoff_preserves_existing_message_history(): void
    {
        [$organization, $conversation] = $this->scenario('preserve-history');
        $department = $this->department($organization, 'History target');
        $message = $conversation->messages()->create([
            'direction' => 'inbound', 'sender_type' => 'customer', 'message_type' => 'text', 'body' => 'Keep this history',
        ]);

        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision($department->id));

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $result->status);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Keep this history']);
    }

    public function test_missing_conversation_is_rejected_without_creating_any_assignment(): void
    {
        $missing = new Conversation;
        $missing->setAttribute($missing->getKeyName(), 987654321);
        $missing->setAttribute('organization_id', 123456789);
        $missing->exists = true;

        $result = app(ConversationHandoffService::class)->execute($missing, $this->decision());

        $this->assertSame(ConversationHandoffStatus::REJECTED, $result->status);
        $this->assertSame('conversation_not_found', $result->reason);
    }

    public function test_handoff_does_not_create_messages_or_issue_http_requests(): void
    {
        [, $conversation] = $this->scenario('no-side-effects');
        $result = app(ConversationHandoffService::class)->execute($conversation, $this->decision());

        $this->assertSame(ConversationHandoffStatus::HANDOFF_UNASSIGNED, $result->status);
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_registration_handoff_persists_one_notification_and_sends_safe_context_once(): void
    {
        [$organization, $conversation] = $this->scenario('registration-notification');
        $department = $this->department($organization, 'Admissions');
        $member = $this->member($organization, $department, 'Admissions CS', phone: '+6281112223333');
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'handoff_test', 'display_name' => 'Test', 'phone_number' => '+6281110000000',
            'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
        $conversation->messages()->create([
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'body' => 'Old pre-reset conversation context',
        ]);
        AiAssistantSetting::forceCreate([
            'organization_id' => $organization->id,
            'registration_url' => 'https://tenant.example.test/register',
        ]);
        $provider = new Phase61HandoffFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
        $service = app(ConversationHandoffService::class);
        $decision = $this->registrationDecision($department->id, $member->id);
        Queue::fake([SendConversationHandoffNotification::class]);

        $first = $service->execute($conversation, $decision);
        $duplicate = $service->execute($conversation, $decision);
        $notification = ConversationHandoffNotification::query()->firstOrFail();

        $this->assertSame(ConversationHandoffStatus::TRANSFERRED, $first->status);
        $this->assertSame(ConversationHandoffStatus::ALREADY_HANDOFF, $duplicate->status);
        $this->assertSame('pending', $notification->status);
        $this->assertSame($member->id, $notification->cs_member_id);
        $this->assertSame('+6281112223333', $notification->recipient_phone);
        $this->assertSame(1, ConversationHandoffNotification::query()->count());
        Queue::assertPushed(SendConversationHandoffNotification::class, 1);

        $conversation->forceFill(['history_generation' => 2])->save();
        $conversation->messages()->create([
            'direction' => 'inbound',
            'history_generation' => 2,
            'sender_type' => 'customer',
            'message_type' => 'text',
            'body' => 'Saya ingin mendaftar.',
        ]);

        $job = new SendConversationHandoffNotification((int) $notification->id);
        $job->handle(app(WhatsAppProviderRegistry::class), app(IndonesianPhoneNumberNormalizer::class));
        $job->handle(app(WhatsAppProviderRegistry::class), app(IndonesianPhoneNumberNormalizer::class));

        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame('handoff-provider-message-1', $notification->fresh()->external_message_id);
        $this->assertSame(1, $provider->sendCount);
        $this->assertStringContainsString('Saya ingin mendaftar.', $provider->lastRequest?->text ?? '');
        $this->assertStringNotContainsString('Old pre-reset conversation context', $provider->lastRequest?->text ?? '');
        $this->assertStringContainsString('https://tenant.example.test/register', $provider->lastRequest?->text ?? '');
        $this->assertStringContainsString('+6281234567890', $provider->lastRequest?->text ?? '');
        $this->assertStringContainsString('Divisi: Admissions', $provider->lastRequest?->text ?? '');
        $this->assertStringContainsString('CS: Admissions CS', $provider->lastRequest?->text ?? '');
        $this->assertStringContainsString('Alasan: registration_intent', $provider->lastRequest?->text ?? '');
        $this->assertSame($connection->id, $provider->lastConnection?->id);
        Http::assertNothingSent();
    }

    public function test_admin_can_explicitly_recover_failed_and_unknown_notifications_once(): void
    {
        foreach (['failed', 'unknown'] as $priorStatus) {
            [$organization, $conversation] = $this->scenario('manual-recovery-'.$priorStatus);
            $department = $this->department($organization, 'Admissions '.$priorStatus);
            $member = $this->member($organization, $department, 'Admissions CS '.$priorStatus, phone: '+6281112223333');
            $connection = $organization->whatsappConnections()->create([
                'provider' => 'handoff_test', 'display_name' => 'Tenant connection', 'phone_number' => '+6281110000000',
                'enabled' => true, 'status' => 'connected', 'is_primary' => true,
            ]);
            $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
            $provider = new Phase61HandoffFakeProvider;
            $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
            Queue::fake([SendConversationHandoffNotification::class]);
            app(ConversationHandoffService::class)->execute($conversation, $this->registrationDecision($department->id, $member->id));
            $notification = ConversationHandoffNotification::query()->where('conversation_id', $conversation->id)->firstOrFail();
            $notification->forceFill(['status' => $priorStatus, 'error_code' => 'previous_delivery_state'])->save();
            $admin = User::forceCreate([
                'organization_id' => $organization->id, 'name' => 'Recovery admin',
                'email' => 'recovery-'.bin2hex(random_bytes(5)).'@example.test', 'password' => 'password', 'role' => 'admin',
            ]);
            $token = $admin->createToken('notification-recovery-test')->plainTextToken;
            Auth::forgetGuards();

            $this->withToken($token)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')
                ->assertAccepted()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.attempt_number', 2);
            $this->assertSame('pending', $notification->fresh()->status);
            $this->assertSame(2, $notification->fresh()->attempt_number);
            $this->assertSame($priorStatus, $notification->fresh()->recovery_history[0]['previous_status']);
            Queue::assertPushed(SendConversationHandoffNotification::class, fn ($job): bool => $job->notificationId === $notification->id && $job->attemptNumber === 2);
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')->assertConflict();
            Queue::assertPushed(SendConversationHandoffNotification::class, 2);

            $job = new SendConversationHandoffNotification((int) $notification->id, 2);
            $job->handle(app(WhatsAppProviderRegistry::class), app(IndonesianPhoneNumberNormalizer::class));
            $this->assertSame('sent', $notification->fresh()->status);
            $this->assertSame(1, $provider->sendCount);

            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')->assertConflict();
            $this->assertSame(1, $provider->sendCount);
        }
        Http::assertNothingSent();
    }

    public function test_recovery_is_admin_only_tenant_scoped_and_refuses_sending_state(): void
    {
        [$organization, $conversation] = $this->scenario('recovery-security');
        $department = $this->department($organization, 'Admissions');
        $member = $this->member($organization, $department, 'Admissions CS');
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'handoff_test', 'display_name' => 'Tenant connection', 'phone_number' => '+6281110000000',
            'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
        Queue::fake([SendConversationHandoffNotification::class]);
        app(ConversationHandoffService::class)->execute($conversation, $this->registrationDecision($department->id, $member->id));
        $notification = ConversationHandoffNotification::query()->where('conversation_id', $conversation->id)->firstOrFail();
        $notification->forceFill(['status' => 'sending'])->save();
        $otherOrganization = Organization::query()->create(['name' => 'Other org', 'slug' => 'other-'.bin2hex(random_bytes(5))]);
        $otherUser = User::forceCreate([
            'organization_id' => $otherOrganization->id, 'name' => 'Other admin',
            'email' => 'other-'.bin2hex(random_bytes(5)).'@example.test', 'password' => 'password', 'role' => 'admin',
        ]);
        $otherToken = $otherUser->createToken('recovery-other-test')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($otherToken)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')->assertNotFound();

        $staff = $this->member($organization, $department, 'Ordinary staff')->user;
        $staffToken = $staff->createToken('recovery-staff-test')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($staffToken)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')->assertForbidden();

        $admin = User::forceCreate([
            'organization_id' => $organization->id, 'name' => 'Tenant admin',
            'email' => 'admin-'.bin2hex(random_bytes(5)).'@example.test', 'password' => 'password', 'role' => 'admin',
        ]);
        $adminToken = $admin->createToken('recovery-admin-test')->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/conversations/'.$conversation->id)
            ->assertOk()->assertJsonPath('data.handoff_notification.can_recover', false);
        $this->withToken($adminToken)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')->assertConflict();
        $this->assertSame('sending', $notification->fresh()->status);
        Queue::assertPushed(SendConversationHandoffNotification::class, 1);

        $notification->forceFill(['updated_at' => now()->subMinutes(6)])->save();
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/conversations/'.$conversation->id)
            ->assertOk()->assertJsonPath('data.handoff_notification.can_recover', true)
            ->assertJsonPath('data.handoff_notification.stale_sending', true);
        $this->withToken($adminToken)->postJson('/api/conversations/'.$conversation->id.'/handoff-notification/retry')
            ->assertAccepted()->assertJsonPath('data.attempt_number', 2);
        $this->assertSame('pending', $notification->fresh()->status);
        $this->assertSame('sending', $notification->fresh()->recovery_history[0]['previous_status']);
        Queue::assertPushed(SendConversationHandoffNotification::class, 2);
    }

    public function test_invalid_cs_recipient_fails_without_calling_provider(): void
    {
        [$organization, $conversation] = $this->scenario('invalid-notification-recipient');
        $department = $this->department($organization, 'Admissions');
        $member = $this->member($organization, $department, 'CS with invalid number', phone: 'not-a-phone');
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'handoff_test', 'display_name' => 'Tenant connection', 'phone_number' => '+6281110000000',
            'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
        $provider = new Phase61HandoffFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
        Queue::fake([SendConversationHandoffNotification::class]);
        app(ConversationHandoffService::class)->execute($conversation, $this->registrationDecision($department->id, $member->id));
        $notification = ConversationHandoffNotification::query()->where('conversation_id', $conversation->id)->firstOrFail();

        (new SendConversationHandoffNotification((int) $notification->id))->handle(
            app(WhatsAppProviderRegistry::class),
            app(IndonesianPhoneNumberNormalizer::class),
        );

        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertSame('invalid_handoff_recipient', $notification->fresh()->error_code);
        $this->assertSame(0, $provider->sendCount);
        Http::assertNothingSent();
    }

    public function test_notification_provider_rejection_is_failed_and_ambiguous_outcome_is_not_retried(): void
    {
        foreach ([['rejected', 'permission_denied', 'failed'], ['ambiguous', 'transport_error', 'unknown']] as [$suffix, $failure, $expected]) {
            [$organization, $conversation] = $this->scenario('notification-'.$suffix);
            $department = $this->department($organization, 'Admissions '.$suffix);
            $member = $this->member($organization, $department, 'Admissions CS '.$suffix);
            $connection = $organization->whatsappConnections()->create([
                'provider' => 'handoff_test', 'display_name' => 'Test', 'phone_number' => '+6281110000000',
                'enabled' => true, 'status' => 'connected', 'is_primary' => true,
            ]);
            $conversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
            $provider = new Phase61HandoffFakeProvider($failure);
            $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
            Queue::fake([SendConversationHandoffNotification::class]);
            app(ConversationHandoffService::class)->execute($conversation, $this->registrationDecision($department->id, $member->id));
            $notification = ConversationHandoffNotification::query()
                ->where('conversation_id', $conversation->id)->firstOrFail();
            $job = new SendConversationHandoffNotification((int) $notification->id);

            $job->handle(app(WhatsAppProviderRegistry::class), app(IndonesianPhoneNumberNormalizer::class));
            $job->handle(app(WhatsAppProviderRegistry::class), app(IndonesianPhoneNumberNormalizer::class));

            $this->assertSame($expected, $notification->fresh()->status, $suffix);
            $this->assertSame($failure === 'transport_error' ? 'delivery_outcome_unknown' : $failure, $notification->fresh()->error_code, $suffix);
            $this->assertSame(1, $provider->sendCount, $suffix);
            $this->assertStringNotContainsString('https://', $provider->lastRequest?->text ?? '', $suffix);
        }
        Http::assertNothingSent();
    }

    private function decision(?int $departmentId = null, ?int $csMemberId = null): AiDecision
    {
        return new AiDecision('GENERAL', 'HANDOFF', null, true, $departmentId, $csMemberId, 'handoff_requested', 1.0);
    }

    private function registrationDecision(int $departmentId, int $csMemberId): AiDecision
    {
        return new AiDecision('REGISTRATION', 'HANDOFF', null, true, $departmentId, $csMemberId, 'registration_intent', 1.0);
    }

    /** @return array{Organization, Conversation} */
    private function scenario(string $suffix, string $status = 'AI_ACTIVE'): array
    {
        $organization = Organization::query()->create([
            'name' => 'Handoff '.$suffix,
            'slug' => 'handoff-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($suffix)).'-'.bin2hex(random_bytes(4)),
        ]);
        $client = $organization->clients()->create([
            'name' => 'Handoff client', 'phone_number' => '+6281234567890', 'status' => 'new',
        ]);
        $conversation = $organization->conversations()->create([
            'client_id' => $client->id, 'status' => $status, 'channel' => 'whatsapp', 'started_at' => now(),
        ]);

        return [$organization, $conversation];
    }

    private function department(Organization $organization, string $name, bool $active = true): Department
    {
        return $organization->departments()->create(['name' => $name.' '.bin2hex(random_bytes(2)), 'active' => $active]);
    }

    private function member(Organization $organization, ?Department $department, string $name, bool $active = true, string $phone = '+6281000000000'): CsMember
    {
        $user = User::forceCreate([
            'organization_id' => $organization->id, 'name' => $name,
            'email' => 'handoff-'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'password', 'role' => 'staff',
        ]);

        return CsMember::forceCreate([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'department_id' => $department?->id,
            'display_name' => $name, 'phone_number' => $phone, 'active' => $active,
        ]);
    }
}

class Phase61HandoffFakeProvider implements WhatsAppProviderInterface
{
    public int $sendCount = 0;

    public ?WhatsAppSendTextRequest $lastRequest = null;

    public ?WhatsAppConnection $lastConnection = null;

    public function __construct(private readonly ?string $failureKind = null) {}

    public function identifier(): string
    {
        return 'handoff_test';
    }

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        return new WhatsAppConnectionVerificationResult(true, 'handoff_test', 'connected');
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        $this->sendCount++;
        $this->lastConnection = $connection;
        $this->lastRequest = $request;
        if ($this->failureKind !== null) {
            throw new WhatsAppProviderException($this->failureKind);
        }

        return new WhatsAppSendResult(true, 'handoff_test', 'handoff-provider-message-'.$this->sendCount, 'sent');
    }
}
