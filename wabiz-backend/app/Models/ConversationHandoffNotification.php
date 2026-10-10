<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'conversation_id', 'cs_member_id', 'history_generation',
    'notification_type', 'recipient_phone', 'status', 'external_message_id',
    'error_code', 'sent_at', 'failed_at', 'unknown_at', 'attempt_number', 'recovery_history',
])]
class ConversationHandoffNotification extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<CsMember, $this> */
    public function csMember(): BelongsTo
    {
        return $this->belongsTo(CsMember::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'history_generation' => 'integer',
            'attempt_number' => 'integer',
            'recovery_history' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'unknown_at' => 'datetime',
        ];
    }
}
