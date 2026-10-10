<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppProviderInterface;
use App\DataTransferObjects\WhatsApp\WhatsAppConnectionVerificationResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendResult;
use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Jobs\SendCampaignRecipient;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignQueueRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private WhatsAppConnection $connection;

    protected function connectionsToTransact()
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'cache.default' => 'array']);
        Http::preventStrayRequests();
        DB::table('jobs')->delete();
        $this->organization = Organization::query()->create(['name' => 'Queue Test', 'slug' => 'queue-test']);
        $this->connection = WhatsAppConnection::query()->forceCreate([
            'organization_id' => $this->organization->id,
            'provider' => 'wppconnect',
            'display_name' => 'Queue fake',
            'phone_number' => '+628111111111',
            'enabled' => true,
            'status' => 'connected',
            'is_primary' => true,
        ]);
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([new RuntimeCampaignProvider]));
    }

    protected function tearDown(): void
    {
        Campaign::query()->where('organization_id', $this->organization->id)->delete();
        $this->connection->delete();
        $this->organization->delete();
        parent::tearDown();
    }

    public function test_database_queue_worker_executes_campaign_recipient_with_fake_provider(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Worker runtime', 'message' => 'Test', 'status' => 'processing', 'total_recipients' => 1,
        ]);
        $recipient = $campaign->recipients()->create(['phone_number' => '+6281234567890', 'status' => 'pending']);
        $provider = new RuntimeCampaignProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));

        SendCampaignRecipient::dispatch($campaign->id, $recipient->id);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1])->assertExitCode(0);

        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => 'sent']);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'completed']);
    }

    public function test_scheduler_dispatches_due_campaign_then_real_queue_worker_runs_delivery(): void
    {
        $campaign = Campaign::query()->create([
            'organization_id' => $this->organization->id,
            'whatsapp_connection_id' => $this->connection->id,
            'name' => 'Scheduler runtime', 'message' => 'Test', 'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(), 'total_recipients' => 1,
        ]);
        $recipient = $campaign->recipients()->create(['phone_number' => '+6281234567891', 'status' => 'pending']);
        $provider = new RuntimeCampaignProvider;
        $this->app->instance(WhatsAppProviderRegistry::class, new WhatsAppProviderRegistry([$provider]));

        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('scheduled', $campaign->fresh()->status);

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1])->assertExitCode(0);
        $this->assertSame('processing', $campaign->fresh()->status);
        $this->assertSame(0, $provider->sendCount);

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1])->assertExitCode(0);
        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('campaign_recipients', ['id' => $recipient->id, 'status' => 'sent']);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'completed']);
    }
}

class RuntimeCampaignProvider implements WhatsAppProviderInterface
{
    public int $sendCount = 0;

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

        return new WhatsAppSendResult(true, 'wppconnect', 'runtime-fake-'.$this->sendCount, 'sent');
    }
}
