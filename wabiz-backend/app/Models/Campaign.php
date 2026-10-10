<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'whatsapp_connection_id', 'created_by', 'name', 'message', 'status', 'scheduled_at', 'started_at', 'sent_at', 'completed_at', 'total_recipients', 'sent_count', 'failed_count', 'unknown_count', 'suppressed_count'])]
class Campaign extends Model
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
