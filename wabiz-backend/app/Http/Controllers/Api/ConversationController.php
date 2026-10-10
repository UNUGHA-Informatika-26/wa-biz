<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListConversationsRequest;
use App\Http\Requests\Api\SendConversationMessageRequest;
use App\Http\Requests\Api\StoreConversationMessageRequest;
use App\Http\Requests\Api\UpdateConversationAssignmentRequest;
use App\Http\Requests\Api\UpdateConversationStatusRequest;
use App\Models\AiAssistantSetting;
use App\Models\Conversation;
use App\Models\CsMember;
use App\Models\Department;
use App\Models\Message;
use App\Services\AI\ConversationReplyAuthorizer;
use App\Services\WhatsApp\WhatsAppMessageSender;
use App\Services\WhatsApp\WhatsAppOutboundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    private const STALE_AI_DELIVERY_MINUTES = 5;

    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'AI_ACTIVE' => ['WAITING_HUMAN'],
        'WAITING_HUMAN' => ['TRANSFERRED_TO_HUMAN'],
        'TRANSFERRED_TO_HUMAN' => ['HUMAN_ACTIVE'],
        'HUMAN_ACTIVE' => ['RESOLVED'],
        'RESOLVED' => ['ARCHIVED', 'AI_ACTIVE'],
        'ARCHIVED' => ['AI_ACTIVE'],
    ];

    public function index(ListConversationsRequest $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');

        $validated = $request->validated();
        $query = Conversation::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with([
                'client:id,name,phone_number,email,group,status',
                'whatsappConnection:id,provider,display_name,phone_number',
                'department:id,name,active',
                'csMember:id,display_name,phone_number,active,department_id',
            ]);

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (($validated['unread'] ?? false) === true || ($validated['unread'] ?? null) === '1') {
            $query->where('unread_count', '>', 0);
        }
        if (array_key_exists('whatsapp_connection_id', $validated)) {
            $validated['whatsapp_connection_id'] === null
                ? $query->whereNull('whatsapp_connection_id')
                : $query->where('whatsapp_connection_id', $validated['whatsapp_connection_id']);
        }

        if (array_key_exists('department_id', $validated)) {
            $validated['department_id'] === null
                ? $query->whereNull('department_id')
                : $query->where('department_id', $validated['department_id']);
        }

        if (array_key_exists('cs_member_id', $validated)) {
            $validated['cs_member_id'] === null
                ? $query->whereNull('cs_member_id')
                : $query->where('cs_member_id', $validated['cs_member_id']);
        }

        if (filled($validated['search'] ?? null)) {
            $search = $validated['search'];
            $query->whereHas('client', function ($clientQuery) use ($search): void {
                $clientQuery->where('name', 'ILIKE', '%'.$search.'%')
                    ->orWhere('phone_number', 'ILIKE', '%'.$search.'%');
            });
        }

        $query->addSelect([
            'active_last_message_id' => Message::query()->select('id')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.history_generation', 'conversations.history_generation')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'active_last_message_direction' => Message::query()->select('direction')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.history_generation', 'conversations.history_generation')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'active_last_message_sender_type' => Message::query()->select('sender_type')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.history_generation', 'conversations.history_generation')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'active_last_message_body' => Message::query()->select('body')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.history_generation', 'conversations.history_generation')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'active_last_message_sent_at' => Message::query()->select('sent_at')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.history_generation', 'conversations.history_generation')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'active_last_message_created_at' => Message::query()->select('created_at')
                ->whereColumn('messages.conversation_id', 'conversations.id')
                ->whereColumn('messages.history_generation', 'conversations.history_generation')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1),
        ]);
        $conversations = $query
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $conversations->getCollection()
                ->map(fn (Conversation $conversation): array => $this->conversationData($conversation))
                ->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function show(Request $request, int $conversation, ConversationReplyAuthorizer $replyAuthorizer): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $conversation = $this->findForOrganization($request, $conversation);
        $conversation->load(['client', 'department', 'csMember', 'whatsappConnection']);

        $data = $this->conversationData($conversation);
        $data['can_reply'] = $replyAuthorizer->canReply($request->user(), $conversation);
        $data['can_take'] = $conversation->status === 'TRANSFERRED_TO_HUMAN'
            && $replyAuthorizer->canTransition($request->user(), $conversation, 'HUMAN_ACTIVE');
        $data['can_resolve'] = $conversation->status === 'HUMAN_ACTIVE'
            && $replyAuthorizer->canTransition($request->user(), $conversation, 'RESOLVED');
        $data['can_reopen'] = in_array($conversation->status, ['RESOLVED', 'ARCHIVED'], true)
            && $replyAuthorizer->canTransition($request->user(), $conversation, 'AI_ACTIVE');
        $data['registration_url'] = $this->registrationHandoffUrl($conversation);
        $notification = $conversation->handoffNotifications()
            ->where('history_generation', (int) $conversation->history_generation)
            ->where('notification_type', 'registration_handoff')
            ->latest('id')->first();
        $data['handoff_notification'] = $notification ? [
            'status' => $notification->status,
            'can_recover' => Gate::allows('manage-organization-operations')
                && (in_array($notification->status, ['failed', 'unknown'], true)
                    || ($notification->status === 'sending' && $notification->updated_at?->lt(now()->subMinutes(5)))),
            'stale_sending' => $notification->status === 'sending' && $notification->updated_at?->lt(now()->subMinutes(5)),
        ] : null;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $conversation = $this->findForOrganization($request, $conversation);
        $messages = $conversation->messages()
            ->where('history_generation', (int) $conversation->history_generation)
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $messages->getCollection()
                ->map(fn ($message): array => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'sender_type' => $message->sender_type,
                    'message_type' => $message->message_type,
                    'body' => $message->body,
                    'external_message_id' => $message->external_message_id,
                    'delivery_status' => $message->delivery_status,
                    'metadata' => $this->safeMetadata($message->metadata),
                    'sent_at' => $message->sent_at,
                    'created_at' => $message->created_at,
                ])
                ->values(),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function reset(Request $request, int $conversation): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $conversationModel = DB::transaction(function () use ($request, $conversation): Conversation {
            $conversationModel = $this->findForOrganization($request, $conversation, lock: true);
            $conversationModel->forceFill([
                'history_generation' => (int) $conversationModel->history_generation + 1,
                'last_message_at' => null,
            ])->save();

            return $conversationModel->refresh();
        });

        return response()->json(['success' => true, 'data' => [
            'conversation_id' => $conversationModel->id,
            'history_generation' => (int) $conversationModel->history_generation,
            'reset_at' => now(),
        ]]);
    }

    public function markRead(Request $request, int $conversation): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $conversationModel = DB::transaction(function () use ($request, $conversation): Conversation {
            $conversationModel = $this->findForOrganization($request, $conversation, lock: true);
            $conversationModel->forceFill(['unread_count' => 0])->save();

            return $conversationModel->refresh();
        });

        return response()->json(['success' => true, 'data' => [
            'conversation_id' => $conversationModel->id,
            'unread_count' => (int) $conversationModel->unread_count,
        ]]);
    }

    public function reconcileAiDelivery(Request $request, int $conversation, int $message): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $organizationId = (int) $request->user()->organization_id;

        $result = DB::transaction(function () use ($request, $conversation, $message, $organizationId, $data): array {
            $conversationModel = Conversation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($conversation)
                ->lockForUpdate()
                ->firstOrFail();
            $messageModel = Message::query()
                ->where('conversation_id', $conversationModel->id)
                ->whereKey($message)
                ->lockForUpdate()
                ->firstOrFail();

            if ($messageModel->direction !== 'outbound' || $messageModel->sender_type !== 'ai'
                || $messageModel->delivery_status !== 'sending') {
                abort(409, 'Only an AI message still marked sending can be reconciled.');
            }
            if (! $messageModel->updated_at || ! $messageModel->updated_at->lt(now()->subMinutes(self::STALE_AI_DELIVERY_MINUTES))) {
                abort(409, 'The AI message is not stale enough to reconcile.');
            }

            $metadata = is_array($messageModel->metadata) ? $messageModel->metadata : [];
            $metadata['delivery_error'] = 'delivery_outcome_unknown';
            $metadata['delivery_reconciliation'] = [
                'previous_status' => 'sending',
                'status' => 'unknown',
                'actor_user_id' => (int) $request->user()->id,
                'reason' => $data['reason'],
                'reconciled_at' => now()->toIso8601String(),
                'conversation_status' => $conversationModel->status,
                'conversation_generation' => (int) $conversationModel->history_generation,
                'message_generation' => (int) $messageModel->history_generation,
                'stale_threshold_minutes' => self::STALE_AI_DELIVERY_MINUTES,
                'retry_queued' => false,
            ];
            $messageModel->forceFill(['delivery_status' => 'unknown', 'metadata' => $metadata])->save();

            return ['message_id' => (int) $messageModel->id, 'status' => 'unknown'];
        });

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function storeMessage(StoreConversationMessageRequest $request, int $conversation, WhatsAppMessageSender $sender): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $data = $request->validated();

        return $this->deliverMessage($request, $conversation, $request->idempotencyKey(), $data['body'], $sender);
    }

    public function sendMessage(SendConversationMessageRequest $request, int $conversation, WhatsAppMessageSender $sender): JsonResponse
    {
        $data = $request->validated();

        return $this->deliverMessage($request, $conversation, $request->idempotencyKey(), $data['body'], $sender);
    }

    private function deliverMessage(Request $request, int $conversation, string $idempotencyKey, string $body, WhatsAppMessageSender $sender): JsonResponse
    {
        Gate::authorize('access-organization-operations');

        try {
            $result = $sender->sendText(
                $request->user(),
                (int) $request->user()->organization_id,
                $conversation,
                $idempotencyKey,
                $body,
            );
        } catch (WhatsAppOutboundException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp message could not be delivered.',
                'code' => $exception->failureKind,
            ], $exception->httpStatus);
        }

        $message = $result->message;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $message->id,
                'direction' => $message->direction,
                'sender_type' => $message->sender_type,
                'message_type' => $message->message_type,
                'body' => $message->body,
                'external_message_id' => $message->external_message_id,
                'delivery_status' => $message->delivery_status,
                'sent_at' => $message->sent_at,
                'created_at' => $message->created_at,
                'replayed' => $result->replayed,
            ],
        ], $result->replayed ? 200 : 201);
    }

    public function updateStatus(UpdateConversationStatusRequest $request, int $conversation, ConversationReplyAuthorizer $replyAuthorizer): JsonResponse
    {
        Gate::authorize('access-organization-operations');

        $result = DB::transaction(function () use ($request, $conversation, $replyAuthorizer): array {
            $conversationModel = $this->findForOrganization($request, $conversation, lock: true);
            $newStatus = $request->validated('status');
            $oldStatus = $conversationModel->status;

            if (! $replyAuthorizer->canTransition($request->user(), $conversationModel, $newStatus)) {
                return ['authorized' => false];
            }

            if (! in_array($newStatus, self::ALLOWED_TRANSITIONS[$oldStatus] ?? [], true)) {
                return ['valid' => false];
            }

            $attributes = ['status' => $newStatus];

            if ($newStatus === 'RESOLVED') {
                $attributes['resolved_at'] = now();
            } elseif ($newStatus === 'ARCHIVED') {
                $attributes['archived_at'] = now();
            } elseif ($newStatus === 'AI_ACTIVE' && in_array($oldStatus, ['RESOLVED', 'ARCHIVED'], true)) {
                $attributes['resolved_at'] = null;
                $attributes['archived_at'] = null;
            }

            $conversationModel->forceFill($attributes)->save();

            return ['valid' => true, 'conversation' => $conversationModel->refresh()];
        });

        if (! ($result['authorized'] ?? true)) {
            return response()->json(['success' => false, 'message' => 'You may not change this conversation state.'], 403);
        }

        if (! $result['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid conversation status transition.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->conversationData($result['conversation']),
        ]);
    }

    public function updateAssignment(UpdateConversationAssignmentRequest $request, int $conversation): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        $result = DB::transaction(function () use ($request, $conversation): array {
            $conversationModel = $this->findForOrganization($request, $conversation, lock: true);
            $validated = $request->validated();
            $organizationId = $request->user()->organization_id;

            $department = null;
            if ($validated['department_id'] !== null) {
                $department = Department::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($validated['department_id'])
                    ->first();

                if (! $department || ! $department->active) {
                    return ['valid' => false, 'message' => 'Department must belong to this organization and be active.'];
                }
            }

            $csMember = null;
            if ($validated['cs_member_id'] !== null) {
                $csMember = CsMember::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($validated['cs_member_id'])
                    ->first();

                if (! $csMember || ! $csMember->active) {
                    return ['valid' => false, 'message' => 'CS member must belong to this organization and be active.'];
                }

                if ($csMember->department_id !== null
                    && (int) $csMember->department_id !== (int) ($validated['department_id'] ?? 0)) {
                    return ['valid' => false, 'message' => 'The selected CS member belongs to a different department.'];
                }
            }

            $conversationModel->forceFill([
                'department_id' => $department?->id,
                'cs_member_id' => $csMember?->id,
                'assigned_at' => $department || $csMember ? now() : null,
            ])->save();

            return ['valid' => true, 'conversation' => $conversationModel->refresh()];
        });

        if (! $result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        $result['conversation']->load(['client', 'department', 'csMember']);

        return response()->json([
            'success' => true,
            'data' => $this->conversationData($result['conversation']),
        ]);
    }

    private function findForOrganization(Request $request, int $conversationId, bool $lock = false): Conversation
    {
        $query = Conversation::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereKey($conversationId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function registrationHandoffUrl(Conversation $conversation): ?string
    {
        if (! is_string($conversation->handoff_reason)
            || ! str_contains(strtolower($conversation->handoff_reason), 'registration')) {
            return null;
        }

        return AiAssistantSetting::query()
            ->where('organization_id', $conversation->organization_id)
            ->value('registration_url');
    }

    /** @return array<string, mixed> */
    private function conversationData(Conversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'status' => $conversation->status,
            'channel' => $conversation->channel,
            'started_at' => $conversation->started_at,
            'last_message_at' => $conversation->last_message_at,
            'resolved_at' => $conversation->resolved_at,
            'archived_at' => $conversation->archived_at,
            'assigned_at' => $conversation->assigned_at,
            'handoff_reason' => $conversation->handoff_reason,
            'handoff_requested_at' => $conversation->handoff_requested_at,
            'history_generation' => (int) $conversation->history_generation,
            'unread_count' => (int) $conversation->unread_count,
            'client' => $conversation->client ? [
                'id' => $conversation->client->id,
                'name' => $conversation->client->name,
                'phone_number' => $conversation->client->phone_number,
                'email' => $conversation->client->email,
                'group' => $conversation->client->group,
                'status' => $conversation->client->status,
            ] : null,
            'whatsapp_connection' => $conversation->whatsappConnection ? [
                'id' => $conversation->whatsappConnection->id,
                'provider' => $conversation->whatsappConnection->provider,
                'display_name' => $conversation->whatsappConnection->display_name,
                'phone_number' => $conversation->whatsappConnection->phone_number,
            ] : null,
            'last_message' => $conversation->active_last_message_id ? [
                'id' => $conversation->active_last_message_id,
                'direction' => $conversation->active_last_message_direction,
                'sender_type' => $conversation->active_last_message_sender_type,
                'body' => $conversation->active_last_message_body,
                'sent_at' => $conversation->active_last_message_sent_at,
                'created_at' => $conversation->active_last_message_created_at,
            ] : null,
            'department' => $conversation->department ? [
                'id' => $conversation->department->id,
                'name' => $conversation->department->name,
                'active' => $conversation->department->active,
            ] : null,
            'cs_member' => $conversation->csMember ? [
                'id' => $conversation->csMember->id,
                'display_name' => $conversation->csMember->display_name,
                'phone_number' => $conversation->csMember->phone_number,
                'active' => $conversation->csMember->active,
                'department_id' => $conversation->csMember->department_id,
                'has_authenticated_identity' => $conversation->csMember->user_id !== null,
            ] : null,
        ];
    }

    /** @return array<string|int, mixed>|null */
    private function safeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        foreach (array_keys($metadata) as $key) {
            if (is_string($key) && preg_match('/secret|token|password|credential|authorization|api[_-]?key/i', $key)) {
                unset($metadata[$key]);
            } elseif (is_array($metadata[$key])) {
                $metadata[$key] = $this->safeMetadata($metadata[$key]);
            }
        }

        return $metadata;
    }
}
