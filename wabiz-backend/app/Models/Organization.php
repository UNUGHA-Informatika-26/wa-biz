<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug'])]
class Organization extends Model
{
    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /** @return HasMany<CsMember, $this> */
    public function csMembers(): HasMany
    {
        return $this->hasMany(CsMember::class);
    }

    /** @return HasOne<AiAssistantSetting, $this> */
    public function aiAssistantSetting(): HasOne
    {
        return $this->hasOne(AiAssistantSetting::class);
    }

    /** @return HasMany<WhatsAppConnection, $this> */
    public function whatsappConnections(): HasMany
    {
        return $this->hasMany(WhatsAppConnection::class);
    }
}
