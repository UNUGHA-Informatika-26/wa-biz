<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendConversationHandoffNotification;
use App\Models\Conversation;
use App\Models\ConversationHandoffNotification;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\IndonesianPhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationHandoffNotificationController extends Controller
{
    private const STALE_SENDING_MINUTES = 5;

    public function retry(Request $request, int $conversation, IndonesianPhoneNumberNormalizer $phoneNormalizer): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $organizationId = (int) $request->user()->organization_id;

        $attemptNumber = DB::transaction(function () use ($request, $conversation, $organizationId, $phoneNormalizer): int {
            $conversationModel = Conversation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($conversation)
                ->lockForUpdate()
                ->firstOrFail();

            $notification = ConversationHandoffNotification::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversationModel->id)
                ->where('history_generation', (int) $conversationModel->history_generation)
                ->where('notification_type', 'registration_handoff')
                ->lockForUpdate()
                ->firstOrFail();

            $staleSending = $notification->status === 'sending'
                && $notification->updated_at?->lt(now()->subMinutes(self::STALE_SENDING_MINUTES));
            if (! in_array($notification->status, ['failed', 'unknown'], true) && ! $staleSending) {
                abort(409, 'Only failed, unknown, or stale sending notifications can be recovered.');
            }

            $member = $notification->csMember()
                ->where('organization_id', $organizationId)
                ->where('active', true)
                ->first();
            $connection = $conversationModel->whatsapp_connection_id
                ? WhatsAppConnection::query()->where('organization_id', $organizationId)
                    ->whereKey($conversationModel->whatsapp_connection_id)->first()
                : null;
            $recipient = is_string($member?->phone_number)
                ? $phoneNormalizer->canonicalize($member->phone_number)
                : '';

            if (! $member || (int) $conversationModel->cs_member_id !== (int) $member->id
                || ($conversationModel->department_id !== null && (int) $conversationModel->department_id !== (int) $member->department_id)
                || ! in_array($conversationModel->status, ['TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE'], true)
                || ! $connection || ! $connection->enabled || $connection->status !== 'connected'
                || preg_match('/^\+[1-9][0-9]{7,14}$/', $recipient) !== 1) {
                abort(409, 'The assigned CS recipient or connected WhatsApp connection is unavailable.');
            }

            $history = is_array($notification->recovery_history) ? $notification->recovery_history : [];
            $history[] = [
                'attempt_number' => (int) $notification->attempt_number,
                'previous_status' => $notification->status,
                'previous_error_code' => $notification->error_code,
                'requested_at' => now()->toIso8601String(),
                'requested_by_user_id' => (int) $request->user()->id,
                'stale_sending_threshold_minutes' => $staleSending ? self::STALE_SENDING_MINUTES : null,
            ];
            $nextAttempt = (int) $notification->attempt_number + 1;
            $notification->forceFill([
                'status' => 'pending',
                'attempt_number' => $nextAttempt,
                'recipient_phone' => $recipient,
                'recovery_history' => $history,
                'external_message_id' => null,
                'error_code' => null,
                'failed_at' => null,
                'unknown_at' => null,
            ])->save();

            DB::afterCommit(fn () => SendConversationHandoffNotification::dispatch((int) $notification->id, $nextAttempt));

            return $nextAttempt;
        });

        return response()->json([
            'success' => true,
            'message' => 'Handoff notification recovery queued.',
            'data' => ['status' => 'pending', 'attempt_number' => $attemptNumber],
        ], 202);
    }
}
