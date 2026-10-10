<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Jobs\DispatchDueCampaigns;
use App\Jobs\SendCampaignRecipient;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\CampaignService;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private WhatsAppConnection $connection;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::query()->create(['name' => 'WABiz Test', 'slug' => 'wabiz-test']);
        $this->admin = User::factory()->make();
        $this->admin->forceFill(['organization_id' => $this->organization->id, 'role' => 'admin'])->save();
        $this->connection = WhatsAppConnection::query()->forceCreate([
            'organization_id' => $this->organization->id, 'provider' => 'wppconnect', 'display_name' => 'Test connection',
            'phone_number' => '+628111111111', 'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $this->client = Client::query()->forceCreate([
            'organization_id' => $this->organization->id, 'name' => 'Recipient', 'phone_number' => '081222222222', 'status' => 'new',
        ]);
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([new FakeCampaignProvider]));
        Http::preventStrayRequests();
        Sanctum::actingAs($this->admin);
    }

    public function test_admin_creates_persisted_draft_with_normalized_tenant_scoped_recipients(): void
    {
        $response = $this->postJson('/api/campaigns', [
            'name' => 'Test campaign', 'message' => 'Hello', 'whatsapp_connection_id' => $this->connection->id,
            'client_ids' => [$this->client->id], 'organization_id' => 999999,
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_recipients', 1);
        $campaignId = $response->json('data.id');
        $this->assertDatabaseHas('campaigns', ['id' => $campaignId, 'organization_id' => $this->organization->id, 'status' => 'draft']);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $campaignId, 'client_id' => $this->client->id, 'phone_number' => '+6281222222222']);
    }

    public function test_foreign_organization_client_is_rejected(): void
    {
        $other = Organization::query()->create(['name' => 'Other', 'slug' => 'other']);
        $foreignClient = Client::query()->forceCreate(['organization_id' => $other->id, 'name' => 'Foreign', 'phone_number' => '081233344455', 'status' => 'new']);
        $this->postJson('/api/campaigns', [
            'name' => 'Test campaign', 'message' => 'Hello', 'whatsapp_connection_id' => $this->connection->id,
            'client_ids' => [$foreignClient->id],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_foreign_organization_connection_and_staff_mutation_are_rejected(): void
    {
        $other = Organization::query()->create(['name' => 'Another tenant', 'slug' => 'another-tenant']);
        $foreignConnection = WhatsAppConnection::query()->forceCreate([
            'organization_id' => $other->id, 'provider' => 'wppconnect', 'display_name' => 'Foreign',
            'phone_number' => '+628199999999', 'enabled' => true, 'status' => 'connected', 'is_primary' => true,
        ]);
        $this->postJson('/api/campaigns', [
            'name' => 'Cross tenant', 'message' => 'Hello', 'whatsapp_connection_id' => $foreignConnection->id,
            'client_ids' => [$this->client->id],
        ])->assertNotFound();

        $staff = User::factory()->make();
        $staff->forceFill(['organization_id' => $this->organization->id, 'role' => 'staff'])->save();
        Sanctum::actingAs($staff);
        $this->postJson('/api/campaigns', [
            'name' => 'Unauthorized', 'message' => 'Hello', 'whatsapp_connection_id' => $this->connection->id,
            'client_ids' => [$this->client->id],
        ])->assertForbidden();
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_campaign_from_another_organization_is_not_visible(): void
    {
        $other = Organization::query()->create(['name' => 'Other Org', 'slug' => 'other-org']);
        $campaign = Campaign::query()->create([
            'organization_id' => $other->id, 'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Foreign campaign', 'message' => 'Hello', 'status' => 'draft',
        ]);
        $this->getJson('/api/campaigns/'.$campaign->id)->assertNotFound();
    }

    public function test_recipient_job_sends_through_provider_and_persists_result(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id, 'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Queued campaign', 'message' => 'Hello', 'status' => 'processing', 'total_recipients' => 1,
        ]);
        $recipient = $campaign->recipients()->create(['phone_number' => '+6281234567890', 'status' => 'pending']);
        $provider = new FakeCampaignProvider;

        (new SendCampaignRecipient($campaign->id, $recipient->id))->handle(
            new WhatsAppProviderRegistry([$provider]), app(CampaignService::class),
        );
        (new SendCampaignRecipient($campaign->id, $recipient->id))->handle(
            new WhatsAppProviderRegistry([$provider]), app(CampaignService::class),
        );

        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => 'sent', 'external_message_id' => 'fake-message-1']);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'completed', 'sent_count' => 1]);
    }

    public function test_schedule_persists_future_time_and_send_transitions_draft_to_processing(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Scheduled test', 'message' => 'Hello', 'status' => 'draft', 'total_recipients' => 1,
        ]);
        $campaign->recipients()->create(['phone_number' => '+6281234567890', 'status' => 'pending']);
        $scheduledAt = now()->addHours(2)->toIso8601String();

        $this->postJson('/api/campaigns/'.$campaign->id.'/schedule', ['scheduled_at' => $scheduledAt])
            ->assertAccepted()->assertJsonPath('data.status', 'scheduled');
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'scheduled']);
        $this->postJson('/api/campaigns/'.$campaign->id.'/schedule', ['scheduled_at' => now()->addHours(3)->toIso8601String()])
            ->assertStatus(409);
        $this->postJson('/api/campaigns/'.$campaign->id.'/send')->assertStatus(409);

        $immediate = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Immediate test', 'message' => 'Hello', 'status' => 'draft', 'total_recipients' => 1,
        ]);
        $immediate->recipients()->create(['phone_number' => '+6281234567891', 'status' => 'pending']);
        $provider = new FakeCampaignProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));
        $this->postJson('/api/campaigns/'.$immediate->id.'/send')->assertAccepted();
        $this->postJson('/api/campaigns/'.$immediate->id.'/send')->assertStatus(409);
        $status = Campaign::query()->findOrFail($immediate->id)->status;
        $this->assertContains($status, ['processing', 'completed']);
        $this->assertSame($status === 'completed' ? 1 : 0, $provider->sendCount);
    }

    public function test_due_campaign_dispatcher_uses_fake_provider_for_scheduled_recipient(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Due campaign', 'message' => 'Hello', 'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(), 'total_recipients' => 1,
        ]);
        $campaign->recipients()->create(['phone_number' => '+6281234567892', 'status' => 'pending']);
        $provider = new FakeCampaignProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));

        app(DispatchDueCampaigns::class)->handle(app(CampaignService::class));
        app(DispatchDueCampaigns::class)->handle(app(CampaignService::class));

        $status = Campaign::query()->findOrFail($campaign->id)->status;
        $this->assertContains($status, ['processing', 'completed']);
        $this->assertSame($status === 'completed' ? 1 : 0, $provider->sendCount);
    }

    public function test_overlapping_duplicate_job_cannot_claim_recipient_while_first_send_is_in_flight(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Overlapping jobs', 'message' => 'Hello', 'status' => 'processing', 'total_recipients' => 1,
        ]);
        $recipient = $campaign->recipients()->create(['phone_number' => '+6281234567893', 'status' => 'pending']);
        $provider = new FakeCampaignProvider;
        $registry = new WhatsAppProviderRegistry([$provider]);
        $job = new SendCampaignRecipient($campaign->id, $recipient->id);
        $provider->duringSend = fn () => $job->handle($registry, app(CampaignService::class));

        $job->handle($registry, app(CampaignService::class));

        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => 'sent']);
    }

    public function test_ambiguous_provider_failure_is_unknown_and_never_automatically_retried(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Ambiguous send', 'message' => 'Hello', 'status' => 'processing', 'total_recipients' => 1,
        ]);
        $recipient = $campaign->recipients()->create(['phone_number' => '+6281234567894', 'status' => 'pending']);
        $provider = new FakeCampaignProvider;
        $provider->sendException = new WhatsAppProviderException('transport_error');
        $registry = new WhatsAppProviderRegistry([$provider]);
        $job = new SendCampaignRecipient($campaign->id, $recipient->id);

        $job->handle($registry, app(CampaignService::class));
        $job->handle($registry, app(CampaignService::class));

        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('campaign_recipients', [
            'id' => $recipient->id, 'status' => 'unknown', 'error' => 'delivery_outcome_unknown',
        ]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'partial', 'unknown_count' => 1]);
    }

    public function test_stale_sending_recipient_is_reconciled_to_unknown_without_resending(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Crashed worker', 'message' => 'Hello', 'status' => 'processing', 'total_recipients' => 1,
        ]);
        $recipient = $campaign->recipients()->create([
            'phone_number' => '+6281234567895', 'status' => 'sending', 'sending_at' => now()->subMinutes(20), 'attempt_count' => 1,
        ]);
        $provider = new FakeCampaignProvider;

        $this->assertSame(1, app(CampaignService::class)->reconcileStaleRecipients(15));
        (new SendCampaignRecipient($campaign->id, $recipient->id))->handle(
            new WhatsAppProviderRegistry([$provider]), app(CampaignService::class),
        );

        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseHas('campaign_recipients', [
            'id' => $recipient->id, 'status' => 'unknown', 'error' => 'delivery_outcome_unknown',
        ]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'partial', 'unknown_count' => 1]);
    }
}

class FakeCampaignProvider implements WhatsAppProviderInterface
{
    public int $sendCount = 0;

    public ?\Closure $duringSend = null;

    public ?\Throwable $sendException = null;

    public function identifier(): string
    {
        return 'wppconnect';
    }

    public function verifyConnection(WhatsAppConnection $connection): WhatsAppConnectionVerificationResult
    {
        return new WhatsAppConnectionVerificationResult(true, 'wppconnect', 'connected');
    }

    public function sendText(WhatsAppConnection $connection, WhatsAppSendTextRequest $request): WhatsAppSendResult
    {
        $this->sendCount++;
        if ($this->duringSend !== null) {
            ($this->duringSend)();
        }
        if ($this->sendException !== null) {
            throw $this->sendException;
        }

        return new WhatsAppSendResult(true, 'wppconnect', 'fake-message-1', 'sent');
    }
}
