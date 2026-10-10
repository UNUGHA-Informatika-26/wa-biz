<?php

namespace App\Services\AI;

use App\DataTransferObjects\AI\AiContext;
use App\Models\AiAssistantSetting;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use InvalidArgumentException;

class AiContextBuilder
{
    private const SETTINGS_FIELDS = [
        'enabled', 'assistant_name', 'communication_style', 'language', 'additional_instructions',
        'institution_name', 'institution_description', 'student_count', 'caregiver_count', 'teacher_count',
        'services', 'address', 'google_maps_url', 'additional_information', 'faqs', 'service_hours',
        'phone', 'website', 'latitude', 'longitude', 'location_description',
        'registration_enabled', 'registration_url', 'registration_status',
        'registration_cs_phone', 'registration_description', 'registration_department_id',
    ];

    public function build(Organization $organization, Conversation $conversation, Message $incomingMessage, string $intent): AiContext
    {
        if ((int) $conversation->organization_id !== (int) $organization->id
            || (int) $incomingMessage->conversation_id !== (int) $conversation->id) {
            throw new InvalidArgumentException('AI context records must belong to the same organization and conversation.');
        }
        if ($conversation->exists && ! $organization->conversations()->whereKey($conversation->id)->exists()) {
            throw new InvalidArgumentException('AI context conversation is not available in this organization.');
        }
        if ($incomingMessage->exists && ! $conversation->messages()->whereKey($incomingMessage->id)->exists()) {
            throw new InvalidArgumentException('AI context message is not available in this conversation.');
        }
        if ($incomingMessage->exists && (int) $incomingMessage->history_generation !== (int) $conversation->history_generation) {
            throw new InvalidArgumentException('AI context message is outside the active conversation history.');
        }

        $setting = AiAssistantSetting::query()->where('organization_id', $organization->id)->first();
        $settings = $setting?->only(self::SETTINGS_FIELDS) ?? [];
        $registration = collect($settings)->only([
            'registration_enabled', 'registration_url', 'registration_status',
            'registration_cs_phone', 'registration_description', 'registration_department_id',
        ])->all();

        $client = Client::query()
            ->where('organization_id', $organization->id)
            ->whereKey($conversation->client_id)
            ->first();

        $messageQuery = $conversation->messages()
            ->where('history_generation', (int) $conversation->history_generation);
        if ($incomingMessage->exists) {
            $messageQuery->whereKeyNot($incomingMessage->id);
        }
        $messages = $messageQuery
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(19)->get()->reverse()->values()
            ->map(fn (Message $message): array => $this->messageData($message))
            ->all();
        $messages[] = $this->messageData($incomingMessage);

        return new AiContext(
            intent: $intent,
            settings: $settings,
            registration: $registration,
            client: $client ? [
                'id' => $client->id,
                'name' => $client->name,
                'group' => $client->group,
                'status' => $client->status,
            ] : null,
            conversation: [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'channel' => $conversation->channel,
                'started_at' => $conversation->started_at,
                'last_message_at' => $conversation->last_message_at,
            ],
            recentMessages: $messages,
        );
    }

    /** @return array<string, mixed> */
    private function messageData(Message $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'sender_type' => $message->sender_type,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'sent_at' => $message->sent_at,
        ];
    }
}
