<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendAiConversationMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 25;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $messageId) {}

    public function uniqueId(): string
    {
        return 'ai-conversation-message:'.$this->messageId;
    }

    public function handle(WhatsAppMessageSender $sender): void
    {
        $sender->sendAiMessage($this->messageId);
    }
}
