<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();
            $table->string('direction');
            $table->string('sender_type');
            $table->string('message_type');
            $table->text('body');
            $table->string('external_message_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('external_message_id');
            $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_at_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_direction_check
            CHECK (direction IN ('inbound', 'outbound'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_sender_type_check
            CHECK (sender_type IN ('customer', 'ai', 'human'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_message_type_check
            CHECK (message_type IN ('text', 'image', 'document', 'audio', 'video', 'system'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
