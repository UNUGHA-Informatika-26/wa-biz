<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id',
    'whatsapp_connection_id',
    'status',
    'channel',
    'history_generation',
    'started_at',
    'last_message_at',
    'resolved_at',
    'archived_at',
    'handoff_reason',
    'handoff_requested_at',
    'unread_count',
])]
class Conversation extends Model
{
    protected $attributes = [
        'history_generation' => 1,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<WhatsAppConnection, $this> */
    public function whatsappConnection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<CsMember, $this> */
    public function csMember(): BelongsTo
    {
        return $this->belongsTo(CsMember::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<ConversationHandoffNotification, $this> */
    public function handoffNotifications(): HasMany
    {
        return $this->hasMany(ConversationHandoffNotification::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_message_at' => 'datetime',
            'resolved_at' => 'datetime',
            'archived_at' => 'datetime',
            'assigned_at' => 'datetime',
            'handoff_requested_at' => 'datetime',
            'history_generation' => 'integer',
            'unread_count' => 'integer',
        ];
    }
}
