<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ConversationReplyAuthorizer
{
    public function canReply(User $user, Conversation $conversation): bool
    {
        if ((int) $user->organization_id !== (int) $conversation->organization_id
            || ! in_array($conversation->status, ['AI_ACTIVE', 'WAITING_HUMAN', 'TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE'], true)) {
            return false;
        }
        if (Gate::forUser($user)->allows('manage-organization-operations')) {
            return true;
        }
        if (! in_array($conversation->status, ['WAITING_HUMAN', 'TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE'], true)
            || $conversation->cs_member_id === null) {
            return false;
        }

        $member = CsMember::query()
            ->where('organization_id', $conversation->organization_id)
            ->whereKey($conversation->cs_member_id)
            ->where('user_id', $user->id)
            ->where('active', true)
            ->first();

        return $member !== null && ($member->department_id === null
            || (int) $member->department_id === (int) $conversation->department_id);
    }

    public function canTransition(User $user, Conversation $conversation, string $newStatus): bool
    {
        if ((int) $user->organization_id !== (int) $conversation->organization_id) {
            return false;
        }
        if (Gate::forUser($user)->allows('manage-organization-operations')) {
            return true;
        }
        if (! $this->canReply($user, $conversation)) {
            return false;
        }

        return in_array([$conversation->status, $newStatus], [
            ['TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE'],
            ['HUMAN_ACTIVE', 'RESOLVED'],
        ], true);
    }
}
