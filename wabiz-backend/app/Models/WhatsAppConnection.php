<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider',
    'display_name',
    'phone_number',
    'phone_number_id',
    'business_account_id',
    'enabled',
    'status',
    'is_primary',
    'credentials',
    'gateway_session_ref',
    'last_connected_at',
    'last_webhook_at',
])]
#[Hidden(['credentials', 'gateway_session_ref'])]
class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<WhatsAppWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WhatsAppWebhookEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'enabled' => 'boolean',
            'is_primary' => 'boolean',
            'last_connected_at' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }
}
