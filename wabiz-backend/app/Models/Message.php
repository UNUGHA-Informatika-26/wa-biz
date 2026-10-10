<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'direction',
    'history_generation',
    'outbound_idempotency_key',
    'delivery_status',
    'sender_type',
    'message_type',
    'body',
    'external_message_id',
    'metadata',
    'sent_at',
])]
class Message extends Model
{
    protected $attributes = [
        'history_generation' => 1,
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function aiSourceMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'ai_source_message_id');
    }

    /** @return HasMany<Message, $this> */
    public function aiResponses(): HasMany
    {
        return $this->hasMany(self::class, 'ai_source_message_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
