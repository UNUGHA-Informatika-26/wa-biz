<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'phone_number', 'email', 'group', 'status', 'notes'])]
class Client extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<ClientWhatsAppConsentEvent, $this> */
    public function whatsappConsentEvents(): HasMany
    {
        return $this->hasMany(ClientWhatsAppConsentEvent::class);
    }

    /** @return HasOne<ClientWhatsAppConsentEvent, $this> */
    public function latestWhatsAppConsent(): HasOne
    {
        return $this->hasOne(ClientWhatsAppConsentEvent::class)->latestOfMany('id');
    }
}
