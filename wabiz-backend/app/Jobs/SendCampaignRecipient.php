<?php

namespace App\Jobs;

use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\WhatsAppConnection;
use App\Services\CampaignService;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendCampaignRecipient implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 86400;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $campaignId, public readonly int $recipientId) {}

    public function uniqueId(): string
    {
        return "campaign:{$this->campaignId}:recipient:{$this->recipientId}";
    }

    public function handle(WhatsAppProviderRegistry $providers, CampaignService $campaigns): void
    {
        $prepared = DB::transaction(function (): ?array {
            $campaign = Campaign::query()->whereKey($this->campaignId)->lockForUpdate()->first();
            $recipient = CampaignRecipient::query()->whereKey($this->recipientId)->where('campaign_id', $this->campaignId)->lockForUpdate()->first();
            if (! $campaign || ! $recipient || $campaign->status !== 'processing' || $recipient->status !== 'pending') {
                return null;
            }
            $connection = WhatsAppConnection::query()->where('organization_id', $campaign->organization_id)->whereKey($campaign->whatsapp_connection_id)->first();
            if (! $connection || ! $connection->enabled || $connection->status !== 'connected') {
                $recipient->forceFill(['status' => 'failed', 'failed_at' => now(), 'error' => 'connection_inactive'])->save();

                return ['failed' => true];
            }
            $recipient->forceFill([
                'status' => 'sending',
                'sending_at' => now(),
                'attempt_count' => $recipient->attempt_count + 1,
                'error' => null,
            ])->save();

            return ['campaign' => $campaign, 'recipient' => $recipient, 'connection' => $connection];
        });
        if (! $prepared || ($prepared['failed'] ?? false)) {
            $campaigns->refreshState($this->campaignId);

            return;
        }
        try {
            $maySend = DB::transaction(function () use ($prepared): bool {
                $campaign = Campaign::query()->whereKey($this->campaignId)->lockForUpdate()->first();
                $recipient = CampaignRecipient::query()
                    ->whereKey($this->recipientId)
                    ->where('campaign_id', $this->campaignId)
                    ->lockForUpdate()
                    ->first();
                if (! $campaign || ! $recipient || $campaign->status !== 'processing' || $recipient->status !== 'sending') {
                    return false;
                }

                $client = $recipient->client_id
                    ? Client::query()
                        ->where('organization_id', $campaign->organization_id)
                        ->whereKey($recipient->client_id)
                        ->with('latestWhatsAppConsent:id,client_id,action,recorded_at')
                        ->first()
                    : null;
                if (! $client || $client->latestWhatsAppConsent?->action !== 'granted') {
                    $recipient->forceFill([
                        'status' => 'suppressed',
                        'suppressed_at' => now(),
                        'error' => 'whatsapp_consent_missing_or_withdrawn',
                    ])->save();

                    return false;
                }

                return true;
            });
            if (! $maySend) {
                $campaigns->refreshState($this->campaignId);

                return;
            }

            $provider = $providers->forConnection($prepared['connection'], (int) $prepared['campaign']->organization_id);
            $result = $provider->sendText($prepared['connection'], new WhatsAppSendTextRequest($prepared['recipient']->phone_number, $prepared['campaign']->message));
            if (! $result->success || ! filled($result->externalMessageId)) {
                throw new WhatsAppProviderException('unexpected_provider_response');
            }
            DB::transaction(function () use ($prepared, $result): void {
                $sentAt = now();
                CampaignRecipient::query()->whereKey($this->recipientId)->where('status', 'sending')
                    ->update(['status' => 'sent', 'sent_at' => $sentAt, 'external_message_id' => $result->externalMessageId, 'updated_at' => $sentAt]);

                $clientId = $prepared['recipient']->client_id;
                if (! $clientId) {
                    return;
                }
                $client = Client::query()->where('organization_id', $prepared['campaign']->organization_id)->whereKey($clientId)->first();
                if (! $client) {
                    return;
                }
                $conversation = Conversation::query()
                    ->where('organization_id', $prepared['campaign']->organization_id)
                    ->where('whatsapp_connection_id', $prepared['connection']->id)
                    ->where('client_id', $client->id)->where('channel', 'whatsapp')
                    ->whereNotIn('status', ['RESOLVED', 'ARCHIVED'])
                    ->orderByRaw('COALESCE(last_message_at, created_at) DESC')->orderByDesc('id')
                    ->lockForUpdate()->first();
                if (! $conversation) {
                    $conversation = Conversation::query()->forceCreate([
                        'organization_id' => $prepared['campaign']->organization_id,
                        'whatsapp_connection_id' => $prepared['connection']->id,
                        'client_id' => $client->id,
                        'channel' => 'whatsapp',
                        'status' => 'AI_ACTIVE',
                        'started_at' => $sentAt,
                    ]);
                }
                $idempotencyKey = "campaign:{$this->campaignId}:recipient:{$this->recipientId}";
                $message = $conversation->messages()->firstOrCreate(
                    ['outbound_idempotency_key' => $idempotencyKey],
                    [
                        'direction' => 'outbound',
                        'history_generation' => (int) $conversation->history_generation,
                        'sender_type' => 'human',
                        'message_type' => 'text',
                        'body' => $prepared['campaign']->message,
                        'external_message_id' => $result->externalMessageId,
                        'delivery_status' => 'sent',
                        'metadata' => ['provider' => $prepared['connection']->provider, 'source' => 'campaign', 'campaign_id' => $this->campaignId],
                        'sent_at' => $sentAt,
                    ],
                );
                if ((int) $message->history_generation === (int) $conversation->history_generation
                    && (! $conversation->last_message_at || $sentAt->greaterThan($conversation->last_message_at))) {
                    $conversation->forceFill(['last_message_at' => $sentAt])->save();
                }
            });
        } catch (Throwable $exception) {
            $kind = $exception instanceof WhatsAppProviderException ? $exception->failureKind : 'delivery_unconfirmed';
            $ambiguous = ! $exception instanceof WhatsAppProviderException || in_array($kind, [
                'transport_error',
                'provider_unavailable',
                'malformed_provider_response',
                'unexpected_provider_response',
                'delivery_state_unconfirmed',
            ], true);

            CampaignRecipient::query()->whereKey($this->recipientId)->where('status', 'sending')->update([
                'status' => $ambiguous ? 'unknown' : 'failed',
                'unknown_at' => $ambiguous ? now() : null,
                'failed_at' => $ambiguous ? null : now(),
                'error' => $ambiguous ? 'delivery_outcome_unknown' : $kind,
                'updated_at' => now(),
            ]);
        }
        $campaigns->refreshState($this->campaignId);
    }
}
