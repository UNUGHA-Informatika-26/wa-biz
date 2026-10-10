<?php

namespace App\Services;

use App\Jobs\SendCampaignRecipient;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CampaignService
{
    public function __construct(private readonly IndonesianPhoneNumberNormalizer $phones) {}

    public function create(int $organizationId, int $userId, array $data): Campaign
    {
        return DB::transaction(function () use ($organizationId, $userId, $data): Campaign {
            $connection = WhatsAppConnection::query()->where('organization_id', $organizationId)->findOrFail($data['whatsapp_connection_id']);
            $this->validateRecipients($organizationId, $data['client_ids']);
            $campaign = Campaign::query()->create([
                'organization_id' => $organizationId, 'whatsapp_connection_id' => $connection->id,
                'created_by' => $userId, 'name' => $data['name'], 'message' => $data['message'],
                'status' => 'draft', 'total_recipients' => count($data['client_ids']),
            ]);
            $this->syncRecipients($campaign, $organizationId, $data['client_ids']);

            return $campaign->load('connection')->loadCount('recipients');
        });
    }

    public function update(Campaign $campaign, array $data): Campaign
    {
        return DB::transaction(function () use ($campaign, $data): Campaign {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Hanya campaign draft yang dapat diubah.');
            }
            $connectionId = $data['whatsapp_connection_id'] ?? $locked->whatsapp_connection_id;
            $connection = WhatsAppConnection::query()->where('organization_id', $locked->organization_id)->findOrFail($connectionId);
            $clientIds = $data['client_ids'] ?? $locked->recipients()->whereNotNull('client_id')->pluck('client_id')->all();
            $this->validateRecipients((int) $locked->organization_id, $clientIds);
            $locked->fill(['whatsapp_connection_id' => $connection->id, 'name' => $data['name'] ?? $locked->name, 'message' => $data['message'] ?? $locked->message]);
            if (array_key_exists('client_ids', $data)) {
                $locked->recipients()->delete();
                $this->syncRecipients($locked, (int) $locked->organization_id, $clientIds);
            }
            $locked->total_recipients = count($clientIds);
            $locked->save();

            return $locked->fresh(['connection'])->loadCount('recipients');
        });
    }

    public function schedule(Campaign $campaign, \DateTimeInterface $when): Campaign
    {
        return DB::transaction(function () use ($campaign, $when): Campaign {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Hanya campaign draft yang dapat dijadwalkan.');
            }
            if ($locked->recipients()->count() < 1 || $when <= now()) {
                throw ValidationException::withMessages(['scheduled_at' => 'Jadwal harus di masa depan dan campaign draft harus memiliki recipient.']);
            }
            $this->assertCampaignRecipientsEligible($locked);
            $this->assertConnectionReady($locked);
            $locked->forceFill(['status' => 'scheduled', 'scheduled_at' => $when])->save();

            return $locked->fresh();
        });
    }

    public function sendNow(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Campaign sudah diproses atau tidak lagi berupa draft.');
            }
            if ($locked->recipients()->count() < 1) {
                throw ValidationException::withMessages(['campaign' => 'Campaign harus berupa draft dengan recipient.']);
            }
            $this->assertCampaignRecipientsEligible($locked);
            $this->assertConnectionReady($locked);
            $locked->forceFill(['status' => 'processing', 'started_at' => now()])->save();
            $ids = $locked->recipients()->where('status', 'pending')->pluck('id')->all();
            DB::afterCommit(fn () => collect($ids)->each(fn ($id) => SendCampaignRecipient::dispatch($locked->id, $id)));

            return $locked->fresh();
        });
    }

    public function dispatchScheduled(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'scheduled' || $locked->scheduled_at?->isFuture()) {
                return;
            }
            try {
                $this->assertConnectionReady($locked);
            } catch (ValidationException) {
                $locked->forceFill(['status' => 'failed', 'completed_at' => now()])->save();

                return;
            }
            $locked->forceFill(['status' => 'processing', 'started_at' => now()])->save();
            $ids = $locked->recipients()->where('status', 'pending')->pluck('id')->all();
            DB::afterCommit(fn () => collect($ids)->each(fn ($id) => SendCampaignRecipient::dispatch($locked->id, $id)));
        });
    }

    public function cancelScheduled(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign): Campaign {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'scheduled') {
                throw new ConflictHttpException('Hanya campaign terjadwal yang dapat dibatalkan.');
            }

            $locked->forceFill(['status' => 'cancelled', 'scheduled_at' => null])->save();

            return $locked->fresh();
        });
    }

    public function deleteDraft(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw new ConflictHttpException('Hanya campaign draft yang dapat dihapus.');
            }

            $locked->delete();
        });
    }

    /** Mark old in-flight sends as ambiguous. They are never automatically retried. */
    public function reconcileStaleRecipients(int $olderThanMinutes = 15): int
    {
        $cutoff = now()->subMinutes($olderThanMinutes);

        return DB::transaction(function () use ($cutoff): int {
            $campaignIds = CampaignRecipient::query()
                ->where('status', 'sending')
                ->whereNotNull('sending_at')
                ->where('sending_at', '<=', $cutoff)
                ->distinct()
                ->pluck('campaign_id');

            $updated = CampaignRecipient::query()
                ->whereIn('campaign_id', $campaignIds)
                ->where('status', 'sending')
                ->whereNotNull('sending_at')
                ->where('sending_at', '<=', $cutoff)
                ->update([
                    'status' => 'unknown',
                    'unknown_at' => now(),
                    'error' => 'delivery_outcome_unknown',
                    'updated_at' => now(),
                ]);

            foreach ($campaignIds as $campaignId) {
                $this->refreshState((int) $campaignId);
            }

            return $updated;
        });
    }

    public function refreshState(int $campaignId): void
    {
        DB::transaction(function () use ($campaignId): void {
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->first();
            if (! $campaign || $campaign->status !== 'processing') {
                return;
            }
            $recipients = $campaign->recipients()->get(['status']);
            $sent = $recipients->whereIn('status', ['sent', 'delivered'])->count();
            $failed = $recipients->where('status', 'failed')->count();
            $unknown = $recipients->where('status', 'unknown')->count();
            $suppressed = $recipients->where('status', 'suppressed')->count();
            $pending = $recipients->whereIn('status', ['pending', 'sending'])->count();
            $campaign->forceFill(['sent_count' => $sent, 'failed_count' => $failed, 'unknown_count' => $unknown, 'suppressed_count' => $suppressed]);
            if ($pending === 0) {
                $campaign->status = $unknown > 0 || ($sent > 0 && ($failed > 0 || $suppressed > 0))
                    ? 'partial'
                    : ($sent > 0 || ($suppressed > 0 && $failed === 0) ? 'completed' : 'failed');
                $campaign->completed_at = now();
                $campaign->sent_at = $sent > 0 ? now() : null;
            }
            $campaign->save();
        });
    }

    private function validateRecipients(int $organizationId, array $clientIds): void
    {
        $clients = Client::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $clientIds)
            ->with('latestWhatsAppConsent:id,client_id,action,recorded_at')
            ->get();
        if ($clients->count() !== count(array_unique($clientIds))) {
            throw ValidationException::withMessages(['client_ids' => 'Recipient tidak ditemukan di organisasi ini.']);
        }
        foreach ($clients as $client) {
            if ($client->latestWhatsAppConsent?->action !== 'granted') {
                throw ValidationException::withMessages(['client_ids' => 'Recipient harus memiliki persetujuan WhatsApp aktif yang tercatat.']);
            }
            $phone = $this->phones->canonicalize((string) $client->phone_number);
            if (! preg_match('/^\+[1-9][0-9]{6,19}$/', $phone)) {
                throw ValidationException::withMessages(['client_ids' => 'Terdapat nomor recipient yang tidak valid.']);
            }
            if ($clients->filter(fn ($other) => $this->phones->canonicalize((string) $other->phone_number) === $phone)->count() > 1) {
                throw ValidationException::withMessages(['client_ids' => 'Recipient memiliki nomor duplikat.']);
            }
        }
    }

    private function assertCampaignRecipientsEligible(Campaign $campaign): void
    {
        $clientIds = $campaign->recipients()->pluck('client_id')->all();
        if ($clientIds === [] || in_array(null, $clientIds, true)) {
            throw ValidationException::withMessages(['client_ids' => 'Semua recipient harus memiliki client dengan persetujuan WhatsApp aktif.']);
        }

        $this->validateRecipients((int) $campaign->organization_id, $clientIds);
    }

    private function syncRecipients(Campaign $campaign, int $organizationId, array $clientIds): void
    {
        $clients = Client::query()->where('organization_id', $organizationId)->whereIn('id', $clientIds)->get();
        foreach ($clients as $client) {
            $campaign->recipients()->create([
                'client_id' => $client->id, 'phone_number' => $this->phones->canonicalize((string) $client->phone_number),
                'original_phone_number' => $client->phone_number, 'status' => 'pending', 'attempt_count' => 0,
            ]);
        }
    }

    private function assertConnectionReady(Campaign $campaign): void
    {
        $connection = WhatsAppConnection::query()->where('organization_id', $campaign->organization_id)->find($campaign->whatsapp_connection_id);
        if (! $connection || ! $connection->enabled || $connection->status !== 'connected') {
            throw ValidationException::withMessages(['whatsapp_connection_id' => 'Koneksi WhatsApp tidak aktif.']);
        }
    }
}
