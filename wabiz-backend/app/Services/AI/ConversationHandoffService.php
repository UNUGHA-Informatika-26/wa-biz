<?php

namespace App\Services\AI;

use App\DataTransferObjects\AI\AiDecision;
use App\DataTransferObjects\AI\ConversationHandoffResult;
use App\DataTransferObjects\AI\ConversationHandoffStatus;
use App\Jobs\SendConversationHandoffNotification;
use App\Models\Conversation;
use App\Models\ConversationHandoffNotification;
use App\Models\CsMember;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationHandoffService
{
    public function execute(Conversation $conversation, AiDecision $decision): ConversationHandoffResult
    {
        if (! $conversation->exists || ! $conversation->getKey() || (int) $conversation->organization_id < 1) {
            return $this->result(ConversationHandoffStatus::REJECTED, 'conversation_invalid');
        }

        return DB::transaction(function () use ($conversation, $decision): ConversationHandoffResult {
            $locked = Conversation::query()
                ->where('organization_id', $conversation->organization_id)
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return $this->result(ConversationHandoffStatus::REJECTED, 'conversation_not_found');
            }

            $target = $this->resolveTarget(
                (int) $locked->organization_id,
                $decision->handoffDepartmentId,
                $decision->handoffCsMemberId,
                $decision->intent !== RegistrationIntentClassifier::REGISTRATION,
            );
            if ($target === null && ($decision->handoffDepartmentId !== null || $decision->handoffCsMemberId !== null)) {
                return $this->result(ConversationHandoffStatus::HANDOFF_UNASSIGNED, 'invalid_or_inactive_target');
            }
            if ($decision->intent === RegistrationIntentClassifier::REGISTRATION
                && ($target === null || $target['cs_member_id'] === null)) {
                $departmentId = $decision->handoffDepartmentId;
                $departmentExists = $departmentId !== null && Department::query()
                    ->where('organization_id', $locked->organization_id)
                    ->whereKey($departmentId)
                    ->where('active', true)
                    ->exists();
                $locked->forceFill([
                    'department_id' => $departmentExists ? $departmentId : null,
                    'cs_member_id' => null,
                    'status' => 'WAITING_HUMAN',
                    'handoff_reason' => $decision->reason,
                    'handoff_requested_at' => $locked->handoff_requested_at ?? now(),
                ])->save();

                return $this->result(ConversationHandoffStatus::HANDOFF_UNASSIGNED, 'no_active_registration_cs');
            }
            if ($target === null) {
                return $this->result(ConversationHandoffStatus::HANDOFF_UNASSIGNED, 'no_unambiguous_active_target');
            }

            if ($locked->status === 'WAITING_HUMAN' && $locked->handoff_requested_at !== null) {
                return $this->result(ConversationHandoffStatus::HANDOFF_UNASSIGNED, 'handoff_pending_manual_review');
            }

            if ($locked->status === 'TRANSFERRED_TO_HUMAN') {
                if ((int) $locked->department_id === (int) ($target['department_id'] ?? 0)
                    && (int) $locked->cs_member_id === (int) ($target['cs_member_id'] ?? 0)) {
                    return $this->result(ConversationHandoffStatus::ALREADY_HANDOFF, 'same_handoff_already_applied', $target['department_id'], $target['cs_member_id']);
                }

                return $this->result(ConversationHandoffStatus::REJECTED, 'conversation_already_transferred_to_different_target');
            }

            if (! in_array($locked->status, ['AI_ACTIVE', 'WAITING_HUMAN'], true)) {
                return $this->result(ConversationHandoffStatus::REJECTED, 'conversation_status_not_handoff_eligible');
            }
            if (($locked->department_id !== null && (int) $locked->department_id !== (int) ($target['department_id'] ?? 0))
                || ($locked->cs_member_id !== null && (int) $locked->cs_member_id !== (int) ($target['cs_member_id'] ?? 0))) {
                return $this->result(ConversationHandoffStatus::REJECTED, 'conversation_has_different_assignment');
            }

            // Use the established AI_ACTIVE -> WAITING_HUMAN -> TRANSFERRED_TO_HUMAN state machine.
            if ($locked->status === 'AI_ACTIVE') {
                $locked->forceFill(['status' => 'WAITING_HUMAN'])->save();
            }
            $locked->forceFill([
                'department_id' => $target['department_id'],
                'cs_member_id' => $target['cs_member_id'],
                'assigned_at' => now(),
                'handoff_reason' => $decision->reason,
                'handoff_requested_at' => $locked->handoff_requested_at ?? now(),
                'status' => 'TRANSFERRED_TO_HUMAN',
            ])->save();

            if ($decision->intent === RegistrationIntentClassifier::REGISTRATION && $target['cs_member_id'] !== null) {
                $member = CsMember::query()
                    ->where('organization_id', $locked->organization_id)
                    ->whereKey($target['cs_member_id'])
                    ->first();
                $notification = ConversationHandoffNotification::query()->firstOrCreate(
                    [
                        'conversation_id' => $locked->id,
                        'history_generation' => (int) $locked->history_generation,
                        'notification_type' => 'registration_handoff',
                    ],
                    [
                        'organization_id' => (int) $locked->organization_id,
                        'cs_member_id' => (int) $target['cs_member_id'],
                        'recipient_phone' => $member?->phone_number,
                        'status' => filled($member?->phone_number) ? 'pending' : 'failed',
                        'error_code' => filled($member?->phone_number) ? null : 'recipient_phone_unavailable',
                        'failed_at' => filled($member?->phone_number) ? null : now(),
                    ],
                );

                if ($notification->wasRecentlyCreated && $notification->status === 'pending') {
                    DB::afterCommit(fn () => SendConversationHandoffNotification::dispatch((int) $notification->id, (int) $notification->attempt_number));
                }
            }

            return $this->result(ConversationHandoffStatus::TRANSFERRED, 'handoff_transferred', $target['department_id'], $target['cs_member_id']);
        }, 3);
    }

    /** @return array{department_id: int|null, cs_member_id: int|null}|null */
    private function resolveTarget(int $organizationId, ?int $departmentId, ?int $csMemberId, bool $allowFallback = true): ?array
    {
        if ($departmentId === null && $csMemberId === null) {
            return $allowFallback ? $this->unambiguousFallback($organizationId) : null;
        }

        $department = null;
        if ($departmentId !== null) {
            $department = Department::query()
                ->where('organization_id', $organizationId)
                ->whereKey($departmentId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            if (! $department) {
                return null;
            }
        }

        $member = null;
        if ($csMemberId !== null) {
            $member = CsMember::query()
                ->where('organization_id', $organizationId)
                ->whereKey($csMemberId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            if (! $member) {
                return null;
            }

            if ($member->department_id !== null) {
                $memberDepartment = Department::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($member->department_id)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();
                if (! $memberDepartment) {
                    return null;
                }
                if ($department && (int) $department->id !== (int) $memberDepartment->id) {
                    return null;
                }
                $department = $memberDepartment;
            }
        }

        return ['department_id' => $department?->id, 'cs_member_id' => $member?->id];
    }

    /** @return array{department_id: int|null, cs_member_id: int|null}|null */
    private function unambiguousFallback(int $organizationId): ?array
    {
        $members = CsMember::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->where(function ($query) use ($organizationId): void {
                $query->whereNull('department_id')->orWhereHas('department', fn ($departmentQuery) => $departmentQuery
                    ->where('organization_id', $organizationId)->where('active', true));
            })
            ->orderBy('id')->lockForUpdate()->get();

        if ($members->count() === 1) {
            $member = $members->first();
            $target = $this->resolveTarget($organizationId, null, (int) $member->id);
            if ($target !== null) {
                return $target;
            }
        }

        $departments = Department::query()
            ->where('organization_id', $organizationId)
            ->where('active', true)
            ->orderBy('id')->lockForUpdate()->get();

        if ($departments->count() === 1) {
            return ['department_id' => (int) $departments->first()->id, 'cs_member_id' => null];
        }

        return null;
    }

    private function result(
        ConversationHandoffStatus $status,
        string $reason,
        ?int $departmentId = null,
        ?int $csMemberId = null,
    ): ConversationHandoffResult {
        Log::info('Conversation handoff evaluated.', array_filter([
            'result' => $status->value,
            'reason' => $reason,
            'department_id' => $departmentId,
            'cs_member_id' => $csMemberId,
        ], fn (mixed $value): bool => $value !== null));

        return new ConversationHandoffResult($status, $reason, $departmentId, $csMemberId);
    }
}
