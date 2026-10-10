<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Jobs\SendAiConversationMessage;
use App\Models\AiAssistantSetting;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConversationApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_conversation_routes_require_authentication(): void
    {
        $this->getJson('/api/conversations')->assertUnauthorized();
        $this->getJson('/api/conversations/1')->assertUnauthorized();
        $this->getJson('/api/conversations/1/messages')->assertUnauthorized();
        $this->postJson('/api/conversations/1/read')->assertUnauthorized();
        $this->postJson('/api/conversations/1/reset')->assertUnauthorized();
        $this->postJson('/api/conversations/1/messages/1/reconcile-ai-delivery', ['reason' => 'Provider reviewed'])->assertUnauthorized();
        $this->postJson('/api/conversations/1/messages', ['body' => 'Hi', 'message_type' => 'text'])->assertUnauthorized();
        $this->patchJson('/api/conversations/1/status', ['status' => 'WAITING_HUMAN'])->assertUnauthorized();
        $this->patchJson('/api/conversations/1/assignment', ['department_id' => null, 'cs_member_id' => null])->assertUnauthorized();
    }

    public function test_list_and_all_conversation_operations_are_tenant_scoped(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $userA = $this->makeUser($organizationA, 'owner');
        $userB = $this->makeUser($organizationB, 'owner');
        $conversationA = $this->makeConversation($organizationA, $this->makeClient($organizationA, 'Client A'));
        $conversationB = $this->makeConversation($organizationB, $this->makeClient($organizationB, 'Client B'));
        $conversationB->messages()->create($this->messagePayload('Secret tenant B message'));
        $tokenA = $this->tokenFor($userA);

        $this->getWithToken($tokenA, '/api/conversations?organization_id='.$organizationB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $conversationA->id)
            ->assertJsonMissing(['name' => 'Client B']);

        $this->getWithToken($this->tokenFor($userB))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $conversationB->id);

        $this->getWithToken($tokenA, '/api/conversations/'.$conversationB->id)->assertNotFound();
        $this->getWithToken($tokenA, '/api/conversations/'.$conversationB->id.'/messages')->assertNotFound();
        $this->patchWithToken($tokenA, '/api/conversations/'.$conversationB->id.'/status', [
            'status' => 'WAITING_HUMAN',
        ])->assertNotFound();
        $this->patchWithToken($tokenA, '/api/conversations/'.$conversationB->id.'/assignment', [
            'department_id' => null,
            'cs_member_id' => null,
        ])->assertNotFound();

        $this->assertSame('AI_ACTIVE', $conversationB->fresh()->status);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationB->id, 'body' => 'Secret tenant B message']);
    }

    public function test_list_filters_search_ordering_and_pagination(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, 'staff');
        $department = $this->makeDepartment($organization, 'Support');
        $member = $this->makeCsMember($organization, $department, 'Agent One');

        $clientOld = $this->makeClient($organization, 'Alpha old', '+62 811 000 0001');
        $clientMiddle = $this->makeClient($organization, 'Alpha middle', '+62 811 000 0002');
        $clientNewest = $this->makeClient($organization, 'Beta newest', '+62 811 000 0003');

        $old = $this->makeConversation($organization, $clientOld, 'AI_ACTIVE', null, now()->subDays(3));
        $middle = $this->makeConversation($organization, $clientMiddle, 'WAITING_HUMAN', now()->subHours(2), now()->subDays(2));
        $newest = $this->makeConversation($organization, $clientNewest, 'WAITING_HUMAN', now()->subHour(), now()->subDay());

        $middle->forceFill(['department_id' => $department->id, 'cs_member_id' => $member->id])->save();
        $newest->forceFill(['department_id' => $department->id, 'cs_member_id' => $member->id])->save();

        $token = $this->tokenFor($user);
        $this->getWithToken($token, '/api/conversations?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('data.1.id', $middle->id);

        $this->getWithToken($token, '/api/conversations?status=WAITING_HUMAN&department_id='.$department->id.'&cs_member_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getWithToken($token, '/api/conversations?search=Alpha')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getWithToken($token, '/api/conversations?search=0003')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $newest->id);
    }

    public function test_unread_filter_and_mark_read_are_persistent_and_tenant_scoped(): void
    {
        $organization = $this->makeOrganization();
        $otherOrganization = $this->makeOrganization();
        $user = $this->makeUser($organization, 'admin');
        $otherUser = $this->makeUser($otherOrganization, 'admin');
        $unread = $this->makeConversation($organization, $this->makeClient($organization, 'Unread'));
        $read = $this->makeConversation($organization, $this->makeClient($organization, 'Read'));
        $foreign = $this->makeConversation($otherOrganization, $this->makeClient($otherOrganization, 'Foreign unread'));
        $unread->forceFill(['unread_count' => 2])->save();
        $foreign->forceFill(['unread_count' => 4])->save();

        $token = $this->tokenFor($user);
        $this->getWithToken($token, '/api/conversations?unread=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $unread->id)
            ->assertJsonPath('data.0.unread_count', 2);
        $this->postWithToken($token, '/api/conversations/'.$unread->id.'/read', [])->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->assertSame(0, (int) $unread->fresh()->unread_count);
        $this->postWithToken($token, '/api/conversations/'.$foreign->id.'/read', [])->assertNotFound();
        $this->assertSame(4, (int) $foreign->fresh()->unread_count);

        $this->postWithToken($this->tokenFor($otherUser), '/api/conversations/'.$unread->id.'/read', [])->assertNotFound();
        $this->assertSame(0, (int) $read->fresh()->unread_count);
    }

    public function test_show_includes_client_department_and_cs_without_organization_id_or_messages(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, 'staff');
        $client = $this->makeClient($organization, 'Inbox client');
        $department = $this->makeDepartment($organization, 'Customer Care');
        $member = $this->makeCsMember($organization, $department, 'CS Agent');
        $conversation = $this->makeConversation($organization, $client);
        $conversation->forceFill([
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
            'assigned_at' => now(),
        ])->save();
        $conversation->messages()->create($this->messagePayload('Not included in detail'));

        $this->getWithToken($this->tokenFor($user), '/api/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.cs_member.id', $member->id)
            ->assertJsonPath('data.cs_member.has_authenticated_identity', true)
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.messages');
    }

    public function test_inbox_detail_marks_assigned_cs_without_a_login_identity(): void
    {
        $organization = $this->makeOrganization();
        $staff = $this->makeUser($organization, 'staff');
        $department = $this->makeDepartment($organization, 'Admissions');
        $member = $this->makeCsMember($organization, $department, 'Unlinked CS');
        $member->forceFill(['user_id' => null])->save();
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Registration client'), 'TRANSFERRED_TO_HUMAN');
        $conversation->forceFill(['department_id' => $department->id, 'cs_member_id' => $member->id])->save();

        $this->getWithToken($this->tokenFor($staff), '/api/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.cs_member.has_authenticated_identity', false)
            ->assertJsonPath('data.can_reply', false);
    }

    public function test_registration_handoff_context_uses_current_organization_settings_and_reply_permission(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $staffA = $this->makeUser($organizationA, 'staff');
        $settingsA = AiAssistantSetting::forceCreate([
            'organization_id' => $organizationA->id,
            'registration_url' => 'https://tenant-a.example.test/apply',
        ]);
        AiAssistantSetting::forceCreate([
            'organization_id' => $organizationB->id,
            'registration_url' => 'https://tenant-b.example.test/apply',
        ]);
        $conversationA = $this->makeConversation($organizationA, $this->makeClient($organizationA, 'Registration client'), 'TRANSFERRED_TO_HUMAN');
        $conversationA->forceFill(['handoff_reason' => 'registration_intent'])->save();
        $conversationB = $this->makeConversation($organizationB, $this->makeClient($organizationB, 'Tenant B'), 'TRANSFERRED_TO_HUMAN');
        $conversationB->forceFill(['handoff_reason' => 'registration_intent'])->save();

        $this->getWithToken($this->tokenFor($staffA), '/api/conversations/'.$conversationA->id)
            ->assertOk()
            ->assertJsonPath('data.registration_url', $settingsA->registration_url)
            ->assertJsonPath('data.can_reply', false);
        $this->getWithToken($this->tokenFor($staffA), '/api/conversations/'.$conversationB->id)->assertNotFound();

        $settingsA->forceFill(['registration_url' => null])->save();
        $this->getWithToken($this->tokenFor($staffA), '/api/conversations/'.$conversationA->id)
            ->assertOk()
            ->assertJsonPath('data.registration_url', null);
    }

    public function test_messages_are_paginated_chronologically_and_tenant_scoped(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $userA = $this->makeUser($organizationA, 'staff');
        $conversationA = $this->makeConversation($organizationA, $this->makeClient($organizationA, 'Timeline'));
        $conversationB = $this->makeConversation($organizationB, $this->makeClient($organizationB, 'Other timeline'));

        $baseTime = now()->subMinutes(60);
        for ($index = 0; $index < 51; $index++) {
            $createdAt = $baseTime->copy()->addMinute($index);
            DB::table('messages')->insert([
                'conversation_id' => $conversationA->id,
                'direction' => 'inbound',
                'sender_type' => 'customer',
                'message_type' => 'text',
                'body' => 'Timeline '.$index,
                'metadata' => json_encode(['safe' => true, 'access_token' => 'hidden']),
                'sent_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
        $conversationB->messages()->create($this->messagePayload('Private B'));

        $token = $this->tokenFor($userA);
        $this->getWithToken($token, '/api/conversations/'.$conversationA->id.'/messages')
            ->assertOk()
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('data.0.body', 'Timeline 0')
            ->assertJsonCount(50, 'data')
            ->assertJsonMissingPath('data.0.metadata.access_token');

        $this->getWithToken($token, '/api/conversations/'.$conversationA->id.'/messages?page=2')
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Timeline 50')
            ->assertJsonCount(1, 'data');

        $this->getWithToken($token, '/api/conversations/'.$conversationB->id.'/messages')->assertNotFound();
    }

    public function test_reset_retains_audit_messages_and_only_exposes_active_generation(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Reset history'));
        $oldMessage = $conversation->messages()->create($this->messagePayload('Old private history'));
        $token = $this->tokenFor($owner);

        $this->postWithToken($token, '/api/conversations/'.$conversation->id.'/reset', [])
            ->assertOk()->assertJsonPath('data.history_generation', 2);
        $conversation->messages()->create([...$this->messagePayload('New active history'), 'history_generation' => 2]);

        $this->assertDatabaseHas('messages', ['id' => $oldMessage->id, 'body' => 'Old private history', 'history_generation' => 1]);
        $this->getWithToken($token, '/api/conversations/'.$conversation->id.'/messages')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.body', 'New active history');
        $this->getWithToken($token, '/api/conversations/'.$conversation->id)
            ->assertOk()->assertJsonPath('data.history_generation', 2);
    }

    public function test_reset_requires_manage_permission_and_is_tenant_scoped(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $staffA = $this->makeUser($organizationA, 'staff');
        $ownerA = $this->makeUser($organizationA, 'owner');
        $ownerB = $this->makeUser($organizationB, 'owner');
        $conversationB = $this->makeConversation($organizationB, $this->makeClient($organizationB, 'Tenant B'));
        $conversationB->messages()->create($this->messagePayload('Keep this history'));
        $uri = '/api/conversations/'.$conversationB->id.'/reset';

        $this->postWithToken($this->tokenFor($staffA), $uri, [])->assertForbidden();
        $this->postWithToken($this->tokenFor($ownerA), $uri, [])->assertNotFound();
        $this->postWithToken($this->tokenFor($ownerB), $uri, [])->assertOk()->assertJsonPath('data.history_generation', 2);
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversationB->id, 'body' => 'Keep this history']);
    }

    public function test_admin_can_reconcile_stale_ai_sending_to_unknown_without_retry(): void
    {
        Queue::fake();
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Stale delivery'), 'RESOLVED');
        $message = $conversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text',
            'body' => 'Potentially delivered response', 'history_generation' => 1,
        ]);
        $message->forceFill(['delivery_status' => 'sending', 'updated_at' => now()->subMinutes(6)])->save();

        $this->postWithToken($this->tokenFor($owner), '/api/conversations/'.$conversation->id.'/messages/'.$message->id.'/reconcile-ai-delivery', [
            'reason' => 'Provider outcome checked; acceptance remains unknown.',
        ])->assertOk()->assertJsonPath('data.status', 'unknown');

        $reconciled = $message->fresh();
        $this->assertSame('unknown', $reconciled->delivery_status);
        $this->assertSame('delivery_outcome_unknown', $reconciled->metadata['delivery_error']);
        $this->assertSame('sending', $reconciled->metadata['delivery_reconciliation']['previous_status']);
        $this->assertSame('unknown', $reconciled->metadata['delivery_reconciliation']['status']);
        $this->assertSame($owner->id, $reconciled->metadata['delivery_reconciliation']['actor_user_id']);
        $this->assertSame('RESOLVED', $reconciled->metadata['delivery_reconciliation']['conversation_status']);
        $this->assertFalse($reconciled->metadata['delivery_reconciliation']['retry_queued']);
        Queue::assertNotPushed(SendAiConversationMessage::class);

        $this->postWithToken($this->tokenFor($owner), '/api/conversations/'.$conversation->id.'/messages/'.$message->id.'/reconcile-ai-delivery', [
            'reason' => 'Repeated reconciliation request.',
        ])->assertConflict();
    }

    public function test_ai_delivery_reconciliation_is_tenant_authorized_and_only_accepts_stale_sending(): void
    {
        $organizationA = $this->makeOrganization();
        $organizationB = $this->makeOrganization();
        $ownerA = $this->makeUser($organizationA, 'owner');
        $ownerB = $this->makeUser($organizationB, 'owner');
        $staffA = $this->makeUser($organizationA, 'staff');
        $conversation = $this->makeConversation($organizationB, $this->makeClient($organizationB, 'Tenant B delivery'));
        $message = $conversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text',
            'body' => 'Tenant B response', 'history_generation' => 1,
        ]);
        $message->forceFill(['delivery_status' => 'sending', 'updated_at' => now()->subMinutes(6)])->save();
        $uri = '/api/conversations/'.$conversation->id.'/messages/'.$message->id.'/reconcile-ai-delivery';
        $body = ['reason' => 'Check ambiguous provider result.'];

        $this->postWithToken($this->tokenFor($ownerA), $uri, $body)->assertNotFound();
        $this->assertSame('sending', $message->fresh()->delivery_status);
        $this->postWithToken($this->tokenFor($staffA), $uri, $body)->assertForbidden();
        $this->postWithToken($this->tokenFor($ownerB), $uri, ['reason' => 'too short'])->assertUnprocessable();
        $this->postWithToken($this->tokenFor($ownerB), $uri, $body)->assertOk();

        $freshConversation = $this->makeConversation($organizationB, $this->makeClient($organizationB, 'Recent sending'));
        $recent = $freshConversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text', 'body' => 'Recent',
        ]);
        $recent->forceFill(['delivery_status' => 'sending'])->save();
        $this->postWithToken($this->tokenFor($ownerB), '/api/conversations/'.$freshConversation->id.'/messages/'.$recent->id.'/reconcile-ai-delivery', $body)->assertConflict();

        $sent = $freshConversation->messages()->create([
            'direction' => 'outbound', 'sender_type' => 'ai', 'message_type' => 'text', 'body' => 'Already sent',
        ]);
        $sent->forceFill(['delivery_status' => 'sent', 'updated_at' => now()->subMinutes(6)])->save();
        $this->postWithToken($this->tokenFor($ownerB), '/api/conversations/'.$freshConversation->id.'/messages/'.$sent->id.'/reconcile-ai-delivery', $body)->assertConflict();
    }

    public function test_legacy_message_route_cannot_create_a_fake_outbound_delivery(): void
    {
        $organization = $this->makeOrganization();
        $anotherOrganization = $this->makeOrganization();
        $user = $this->makeUser($organization, 'staff');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Message recipient'));
        $otherConversation = $this->makeConversation($anotherOrganization, $this->makeClient($anotherOrganization, 'Other'));

        $this->postWithToken($this->tokenFor($user), '/api/conversations/'.$conversation->id.'/messages', [
            'body' => 'Operator reply',
            'message_type' => 'text',
            'metadata' => ['source' => 'inbox', 'access_token' => 'must-be-rejected'],
            'direction' => 'inbound',
            'sender_type' => 'ai',
            'organization_id' => $anotherOrganization->id,
            'conversation_id' => $otherConversation->id,
            'external_message_id' => 'fake-external-id',
        ])->assertUnprocessable();

        Auth::forgetGuards();
        $this->withToken($this->tokenFor($user))->withHeader('Idempotency-Key', 'legacy-test-idempotency-1')
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'Operator reply',
                'message_type' => 'text',
                'metadata' => ['source' => 'inbox'],
            ])->assertUnprocessable();

        Auth::forgetGuards();
        $this->withToken($this->tokenFor($user))->withHeader('Idempotency-Key', 'legacy-test-idempotency-2')
            ->postJson('/api/conversations/'.$conversation->id.'/messages', [
                'body' => 'Operator reply',
                'message_type' => 'text',
            ])->assertForbidden();

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'body' => 'Operator reply']);
        $this->assertNull($conversation->fresh()->last_message_at);
        $this->assertSame('AI_ACTIVE', $conversation->fresh()->status);
        $this->assertDatabaseHas('conversations', ['id' => $otherConversation->id]);
    }

    public function test_successful_human_send_uses_fake_provider_and_persists_human_takeover_before_reply(): void
    {
        $organization = $this->makeOrganization();
        $client = $this->makeClient($organization, 'Reply recipient', '+6281234567890');
        $department = $this->makeDepartment($organization, 'Admissions');
        $member = $this->makeCsMember($organization, $department, 'Assigned Admissions CS');
        $user = $member->user;
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'phase6_test', 'display_name' => 'Test connection', 'phone_number' => '+6281111111111',
            'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $conversation = $this->makeConversation($organization, $client, 'TRANSFERRED_TO_HUMAN');
        $conversation->forceFill([
            'whatsapp_connection_id' => $connection->id,
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
        ])->save();
        $provider = new Phase6ConversationFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));

        Auth::forgetGuards();
        $response = $this->withToken($this->tokenFor($user))->withHeader('Idempotency-Key', 'phase6-human-reply-1')
            ->postJson('/api/conversations/'.$conversation->id.'/messages/send', [
                'body' => 'Saya bantu pendaftaran.',
            ])->assertCreated();

        $this->assertSame(1, $provider->sendCount);
        $this->assertSame('HUMAN_ACTIVE', $conversation->fresh()->status);
        $this->assertDatabaseHas('messages', [
            'id' => $response->json('data.id'),
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => 'human',
            'delivery_status' => 'sent',
            'body' => 'Saya bantu pendaftaran.',
        ]);
        $this->assertSame(0, (int) $conversation->fresh()->unread_count);
    }

    public function test_reply_requires_assigned_active_cs_or_explicit_admin_override(): void
    {
        $organization = $this->makeOrganization();
        $otherOrganization = $this->makeOrganization();
        $admin = $this->makeUser($organization, 'admin');
        $ordinaryStaff = $this->makeUser($organization, 'staff');
        $department = $this->makeDepartment($organization, 'Admissions');
        $otherDepartment = $this->makeDepartment($organization, 'Finance');
        $assigned = $this->makeCsMember($organization, $department, 'Assigned CS');
        $replacement = $this->makeCsMember($organization, $department, 'Replacement CS');
        $differentDivision = $this->makeCsMember($organization, $otherDepartment, 'Other Division CS');
        $foreignCs = $this->makeCsMember($otherOrganization, null, 'Foreign CS');
        $client = $this->makeClient($organization, 'Assigned recipient', '+6281234567890');
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'phase6_test', 'display_name' => 'Test connection', 'phone_number' => '+6281111111111',
            'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $conversation = $this->makeConversation($organization, $client, 'TRANSFERRED_TO_HUMAN');
        $conversation->forceFill([
            'whatsapp_connection_id' => $connection->id,
            'department_id' => $department->id,
            'cs_member_id' => $assigned->id,
        ])->save();
        $provider = new Phase6ConversationFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
        $uri = '/api/conversations/'.$conversation->id.'/messages/send';

        $this->sendWithToken($ordinaryStaff, 'unauthorized-staff-0001', $uri, 'Unauthorized')
            ->assertForbidden()->assertJsonPath('code', 'conversation_reply_forbidden');
        $this->sendWithToken($differentDivision->user, 'other-division-00001', $uri, 'Wrong division')
            ->assertForbidden();
        $this->sendWithToken($foreignCs->user, 'foreign-tenant-00001', $uri, 'Foreign tenant')
            ->assertNotFound();
        $this->assertSame(0, $provider->sendCount);

        $this->sendWithToken($assigned->user, 'assigned-cs-reply-0001', $uri, 'Assigned reply')->assertCreated();
        $this->assertSame('HUMAN_ACTIVE', $conversation->fresh()->status);

        $this->patchWithToken($this->tokenFor($admin), '/api/conversations/'.$conversation->id.'/assignment', [
            'department_id' => $department->id,
            'cs_member_id' => $replacement->id,
        ])->assertOk();
        $this->sendWithToken($assigned->user, 'previous-cs-reply-0001', $uri, 'Previous assignee')->assertForbidden();
        $this->sendWithToken($replacement->user, 'replacement-cs-reply-001', $uri, 'Replacement reply')->assertCreated();
        $replacement->forceFill(['active' => false])->save();
        $this->sendWithToken($replacement->user, 'inactive-cs-reply-00001', $uri, 'Inactive reply')->assertForbidden();

        $this->sendWithToken($admin, 'admin-override-reply-01', $uri, 'Admin override')->assertCreated();
        $this->assertSame(3, $provider->sendCount);
        $this->assertSame(3, $conversation->messages()->where('direction', 'outbound')->count());
    }

    public function test_ai_mode_requires_admin_override_and_resolved_conversation_must_be_reactivated(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $staff = $this->makeUser($organization, 'staff');
        $client = $this->makeClient($organization, 'AI mode recipient');
        $connection = $organization->whatsappConnections()->create([
            'provider' => 'phase6_test', 'display_name' => 'Test connection', 'phone_number' => '+6281111111111',
            'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $aiConversation = $this->makeConversation($organization, $client, 'AI_ACTIVE');
        $resolved = $this->makeConversation($organization, $client, 'RESOLVED');
        $aiConversation->forceFill(['whatsapp_connection_id' => $connection->id])->save();
        $resolved->forceFill(['whatsapp_connection_id' => $connection->id])->save();
        $provider = new Phase6ConversationFakeProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));

        $this->sendWithToken($staff, 'ai-mode-staff-reply-001', '/api/conversations/'.$aiConversation->id.'/messages/send', 'No takeover')->assertForbidden();
        $this->sendWithToken($owner, 'ai-mode-owner-reply-001', '/api/conversations/'.$aiConversation->id.'/messages/send', 'Admin takeover')->assertCreated();
        $this->sendWithToken($owner, 'resolved-owner-reply-01', '/api/conversations/'.$resolved->id.'/messages/send', 'Must reactivate')->assertForbidden();
        $this->assertSame(1, $provider->sendCount);

        $this->patchWithToken($this->tokenFor($owner), '/api/conversations/'.$resolved->id.'/status', ['status' => 'AI_ACTIVE'])->assertOk();
        $this->sendWithToken($owner, 'reactivated-owner-reply-01', '/api/conversations/'.$resolved->id.'/messages/send', 'After reactivation')->assertCreated();
        $this->assertSame(2, $provider->sendCount);
    }

    public function test_only_assigned_cs_can_take_or_resolve_a_human_conversation(): void
    {
        $organization = $this->makeOrganization();
        $department = $this->makeDepartment($organization, 'Admissions');
        $assigned = $this->makeCsMember($organization, $department, 'Assigned status CS');
        $other = $this->makeCsMember($organization, $department, 'Other status CS');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Status recipient'), 'TRANSFERRED_TO_HUMAN');
        $conversation->forceFill(['department_id' => $department->id, 'cs_member_id' => $assigned->id])->save();
        $uri = '/api/conversations/'.$conversation->id.'/status';

        $this->patchWithToken($this->tokenFor($other->user), $uri, ['status' => 'HUMAN_ACTIVE'])->assertForbidden();
        $this->patchWithToken($this->tokenFor($assigned->user), $uri, ['status' => 'HUMAN_ACTIVE'])->assertOk();
        $this->patchWithToken($this->tokenFor($other->user), $uri, ['status' => 'RESOLVED'])->assertForbidden();
        $this->patchWithToken($this->tokenFor($assigned->user), $uri, ['status' => 'RESOLVED'])->assertOk();
        $this->assertSame('RESOLVED', $conversation->fresh()->status);
    }

    public function test_status_transitions_and_timestamp_side_effects(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, 'admin');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Lifecycle'));
        $token = $this->tokenFor($user);

        $this->patchWithToken($token, '/api/conversations/'.$conversation->id.'/status', ['status' => 'RESOLVED'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid conversation status transition.');

        foreach (['WAITING_HUMAN', 'TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE', 'RESOLVED'] as $status) {
            $this->patchWithToken($token, '/api/conversations/'.$conversation->id.'/status', ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $resolvedAt = $conversation->fresh()->resolved_at;
        $this->assertNotNull($resolvedAt);

        $this->patchWithToken($token, '/api/conversations/'.$conversation->id.'/status', ['status' => 'ARCHIVED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');
        $this->assertNotNull($conversation->fresh()->archived_at);
        $this->assertEquals($resolvedAt, $conversation->fresh()->resolved_at);

        $this->patchWithToken($token, '/api/conversations/'.$conversation->id.'/status', ['status' => 'AI_ACTIVE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'AI_ACTIVE');
        $this->assertNull($conversation->fresh()->resolved_at);
        $this->assertNull($conversation->fresh()->archived_at);
    }

    public function test_resolved_conversation_can_reopen_directly_to_ai_active(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser($organization, 'admin');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Reopen'), 'RESOLVED');
        $conversation->forceFill(['resolved_at' => now()->subHour()])->save();

        $this->patchWithToken($this->tokenFor($user), '/api/conversations/'.$conversation->id.'/status', [
            'status' => 'AI_ACTIVE',
        ])->assertOk();

        $this->assertNull($conversation->fresh()->resolved_at);
    }

    public function test_assignment_roles_activity_tenant_and_department_match_rules(): void
    {
        $organization = $this->makeOrganization();
        $otherOrganization = $this->makeOrganization();
        $owner = $this->makeUser($organization, 'owner');
        $admin = $this->makeUser($organization, 'admin');
        $staff = $this->makeUser($organization, 'staff');
        $department = $this->makeDepartment($organization, 'Support');
        $otherDepartment = $this->makeDepartment($organization, 'Billing');
        $inactiveDepartment = $this->makeDepartment($organization, 'Inactive', active: false);
        $foreignDepartment = $this->makeDepartment($otherOrganization, 'Foreign');
        $member = $this->makeCsMember($organization, $department, 'Support CS');
        $unassignedMember = $this->makeCsMember($organization, null, 'General CS');
        $inactiveMember = $this->makeCsMember($organization, $department, 'Inactive CS', active: false);
        $foreignMember = $this->makeCsMember($otherOrganization, null, 'Foreign CS');
        $conversation = $this->makeConversation($organization, $this->makeClient($organization, 'Assignment'));
        $uri = '/api/conversations/'.$conversation->id.'/assignment';

        $this->patchWithToken($this->tokenFor($owner), $uri, [
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
        ])->assertOk()->assertJsonPath('data.department.id', $department->id);
        $this->assertNotNull($conversation->fresh()->assigned_at);

        $this->patchWithToken($this->tokenFor($admin), $uri, [
            'department_id' => $department->id,
            'cs_member_id' => $member->id,
        ])->assertOk();

        $this->patchWithToken($this->tokenFor($staff), $uri, [
            'department_id' => null,
            'cs_member_id' => null,
        ])->assertForbidden();

        foreach ([
            ['department_id' => $inactiveDepartment->id, 'cs_member_id' => null],
            ['department_id' => $department->id, 'cs_member_id' => $inactiveMember->id],
            ['department_id' => $foreignDepartment->id, 'cs_member_id' => null],
            ['department_id' => null, 'cs_member_id' => $foreignMember->id],
            ['department_id' => $otherDepartment->id, 'cs_member_id' => $member->id],
        ] as $invalidAssignment) {
            $this->patchWithToken($this->tokenFor($admin), $uri, $invalidAssignment)->assertUnprocessable();
        }

        $this->patchWithToken($this->tokenFor($admin), $uri, [
            'department_id' => $department->id,
            'cs_member_id' => $unassignedMember->id,
        ])->assertOk();

        $this->patchWithToken($this->tokenFor($owner), $uri, [
            'department_id' => null,
            'cs_member_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.department', null)
            ->assertJsonPath('data.cs_member', null);
        $this->assertNull($conversation->fresh()->assigned_at);
    }

    private function makeOrganization(): Organization
    {
        return Organization::query()->create([
            'name' => 'Conversation test organization',
            'slug' => 'conversation-'.bin2hex(random_bytes(6)),
        ]);
    }

    private function makeUser(Organization $organization, string $role): User
    {
        return User::forceCreate([
            'organization_id' => $organization->id,
            'name' => 'Conversation Test User',
            'email' => 'conversation-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'correct-password',
            'role' => $role,
        ]);
    }

    private function makeClient(Organization $organization, string $name, string $phone = '+62 812 000 0000'): Client
    {
        return $organization->clients()->create([
            'name' => $name,
            'phone_number' => $phone,
            'status' => 'customer',
        ]);
    }

    private function makeDepartment(Organization $organization, string $name, bool $active = true): Department
    {
        return $organization->departments()->create([
            'name' => $name,
            'active' => $active,
        ]);
    }

    private function makeCsMember(Organization $organization, ?Department $department, string $name, bool $active = true): CsMember
    {
        $user = $this->makeUser($organization, 'staff');

        return CsMember::forceCreate([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'department_id' => $department?->id,
            'display_name' => $name,
            'active' => $active,
        ]);
    }

    private function makeConversation(
        Organization $organization,
        Client $client,
        string $status = 'AI_ACTIVE',
        mixed $lastMessageAt = null,
        mixed $createdAt = null,
    ): Conversation {
        return Conversation::forceCreate([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'status' => $status,
            'channel' => 'whatsapp',
            'last_message_at' => $lastMessageAt,
            'created_at' => $createdAt ?? now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $body): array
    {
        return [
            'direction' => 'inbound',
            'sender_type' => 'customer',
            'message_type' => 'text',
            'body' => $body,
        ];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('conversation-api-test')->plainTextToken;
    }

    private function getWithToken(string $token, string $uri = '/api/conversations')
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson($uri);
    }

    /** @param array<string, mixed> $data */
    private function postWithToken(string $token, string $uri, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->postJson($uri, $data);
    }

    private function sendWithToken(User $user, string $idempotencyKey, string $uri, string $body)
    {
        Auth::forgetGuards();

        return $this->withToken($this->tokenFor($user))
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson($uri, ['body' => $body]);
    }

    /** @param array<string, mixed> $data */
    private function patchWithToken(string $token, string $uri, array $data)
    {
        Auth::forgetGuards();

        return $this->withToken($token)->patchJson($uri, $data);
    }
}

class Phase6ConversationFakeProvider implements WhatsAppProviderInterface
{
    public int $sendCount = 0;

    public function identifier(): string
    {
        return 'phase6_test';
    }

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        return new WhatsAppConnectionVerificationResult(true, 'phase6_test', 'connected');
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        $this->sendCount++;

        return new WhatsAppSendResult(true, 'phase6_test', 'fake-phase6-message-'.$this->sendCount, 'sent');
    }
}
