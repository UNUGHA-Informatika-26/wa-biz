<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campaign_id', 'client_id', 'phone_number', 'original_phone_number', 'status', 'sending_at', 'sent_at', 'failed_at', 'unknown_at', 'suppressed_at', 'attempt_count', 'error', 'external_message_id'])]
class CampaignRecipient extends Model
{
    protected function casts(): array
    {
        return ['sending_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'unknown_at' => 'immutable_datetime', 'suppressed_at' => 'immutable_datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
