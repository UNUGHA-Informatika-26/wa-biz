<?php

namespace App\DataTransferObjects\AI;

final readonly class AiContext
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $registration
     * @param  array<string, mixed>|null  $client
     * @param  array<string, mixed>  $conversation
     * @param  list<array<string, mixed>>  $recentMessages
     */
    public function __construct(
        public string $intent,
        public array $settings,
        public array $registration,
        public ?array $client,
        public array $conversation,
        public array $recentMessages,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'settings' => $this->settings,
            'registration' => $this->registration,
            'client' => $this->client,
            'conversation' => $this->conversation,
            'recent_messages' => $this->recentMessages,
        ];
    }
}
