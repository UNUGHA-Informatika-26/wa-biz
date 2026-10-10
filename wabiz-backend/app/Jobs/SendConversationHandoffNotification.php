<?php

namespace App\Jobs;

use App\DataTransferObjects\WhatsApp\WhatsAppSendTextRequest;
use App\Models\AiAssistantSetting;
use App\Models\Conversation;
use App\Models\ConversationHandoffNotification;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
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

class SendConversationHandoffNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $notificationId, public readonly int $attemptNumber = 1) {}

    public function uniqueId(): string
    {
        return 'conversation-handoff-notification:'.$this->notificationId.':'.$this->attemptNumber;
    }

    public function handle(
        WhatsAppProviderRegistry $providers,
        IndonesianPhoneNumberNormalizer $phoneNormalizer,
    ): void {
        $prepared = DB::transaction(function () use ($phoneNormalizer): ?array {
            $notification = ConversationHandoffNotification::query()
                ->whereKey($this->notificationId)
                ->lockForUpdate()
                ->first();
            if (! $notification || $notification->status !== 'pending'
                || (int) $notification->attempt_number !== $this->attemptNumber) {
                return null;
            }

            $conversation = Conversation::query()
                ->where('organization_id', $notification->organization_id)
                ->whereKey($notification->conversation_id)
                ->with(['client', 'department', 'csMember'])
                ->first();
            $member = $notification->cs_member_id
                ? $notification->csMember()->where('organization_id', $notification->organization_id)->first()
                : null;
            $connection = $conversation?->whatsapp_connection_id
                ? WhatsAppConnection::query()
                    ->where('organization_id', $notification->organization_id)
                    ->whereKey($conversation->whatsapp_connection_id)
                    ->first()
                : null;

            if (! $conversation || ! $member || ! $member->active || ! $connection
                || ! $connection->enabled || $connection->status !== 'connected'
                || ! is_string($notification->recipient_phone) || trim($notification->recipient_phone) === '') {
                $notification->forceFill([
                    'status' => 'failed',
                    'error_code' => 'handoff_recipient_or_connection_unavailable',
                    'failed_at' => now(),
                ])->save();

                return null;
            }

            $phone = $phoneNormalizer->canonicalize($notification->recipient_phone);
            if (preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
                $notification->forceFill(['status' => 'failed', 'error_code' => 'invalid_handoff_recipient', 'failed_at' => now()])->save();

                return null;
            }
            $notification->forceFill(['status' => 'sending', 'error_code' => null])->save();
            $settings = AiAssistantSetting::query()
                ->where('organization_id', $notification->organization_id)
                ->first(['registration_url']);
            $history = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('history_generation', (int) $conversation->history_generation)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get()
                ->reverse()
                ->map(fn (Message $message): string => mb_substr(trim((string) $message->body), 0, 300))
                ->filter(fn (string $body): bool => $body !== '')
                ->implode("\n");

            return [
                'notification' => $notification,
                'conversation' => $conversation,
                'connection' => $connection,
                'phone' => $phone,
                'settings_url' => $settings?->registration_url,
                'history' => $history,
            ];
        });

        if (! $prepared) {
            return;
        }

        try {
            $provider = $providers->forConnection($prepared['connection'], (int) $prepared['notification']->organization_id);
            $result = $provider->sendText(
                $prepared['connection'],
                new WhatsAppSendTextRequest(
                    $prepared['phone'],
                    $this->messageBody($prepared['conversation'], $prepared['settings_url'], $prepared['history']),
                ),
            );
            if (! $result->success || ! filled($result->externalMessageId)) {
                throw new WhatsAppProviderException('unexpected_provider_response');
            }

            ConversationHandoffNotification::query()->whereKey($this->notificationId)->where('attempt_number', $this->attemptNumber)->where('status', 'sending')->update([
                'status' => 'sent',
                'external_message_id' => $result->externalMessageId,
                'sent_at' => now(),
                'error_code' => null,
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $failureKind = $exception instanceof WhatsAppProviderException ? $exception->failureKind : 'delivery_outcome_unknown';
            $ambiguous = ! $exception instanceof WhatsAppProviderException || in_array($failureKind, [
                'transport_error', 'provider_unavailable', 'malformed_provider_response',
                'unexpected_provider_response', 'delivery_state_unconfirmed',
            ], true);

            ConversationHandoffNotification::query()->whereKey($this->notificationId)->where('attempt_number', $this->attemptNumber)->where('status', 'sending')->update([
                'status' => $ambiguous ? 'unknown' : 'failed',
                'error_code' => $ambiguous ? 'delivery_outcome_unknown' : $failureKind,
                'unknown_at' => $ambiguous ? now() : null,
                'failed_at' => $ambiguous ? null : now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function messageBody(Conversation $conversation, ?string $registrationUrl, string $history): string
    {
        $clientName = trim((string) ($conversation->client?->name ?? ''));
        $reason = trim((string) ($conversation->handoff_reason ?? 'registration_support_requested'));
        $body = "WABiz: Percakapan pendaftaran membutuhkan bantuan CS.\n"
            .'Client: '.($clientName !== '' ? $clientName : 'Nama belum tersedia')."\n"
            .'Nomor WhatsApp: '.($conversation->client?->phone_number ?? 'Tidak tersedia')."\n"
            .'Divisi: '.($conversation->department?->name ?? 'Tidak tersedia')."\n"
            .'CS: '.($conversation->csMember?->display_name ?? 'Tidak tersedia')."\n"
            .'Alasan: '.$reason."\n"
            .'Conversation ID: '.$conversation->id."\n"
            .'Waktu handoff: '.($conversation->handoff_requested_at?->toISOString() ?? now()->toISOString());

        if ($this->isSafeUrl($registrationUrl)) {
            $body .= "\nLink pendaftaran: ".$registrationUrl;
        }
        if ($history !== '') {
            $body .= "\nKonteks percakapan terbaru:\n".mb_substr($history, 0, 900);
        }

        return mb_substr($body, 0, 4000);
    }

    private function isSafeUrl(?string $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
