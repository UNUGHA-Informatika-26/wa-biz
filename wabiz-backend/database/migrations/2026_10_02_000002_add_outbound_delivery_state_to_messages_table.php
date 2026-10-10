<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('outbound_idempotency_key', 128)->nullable();
            $table->string('delivery_status', 20)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_delivery_status_check
            CHECK (delivery_status IS NULL OR delivery_status IN ('pending', 'sent', 'failed', 'unknown'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX messages_conversation_outbound_idempotency_unique
            ON messages (conversation_id, outbound_idempotency_key)
            WHERE outbound_idempotency_key IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX messages_conversation_external_id_unique
            ON messages (conversation_id, external_message_id)
            WHERE external_message_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_conversation_external_id_unique');
        DB::statement('DROP INDEX IF EXISTS messages_conversation_outbound_idempotency_unique');
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_delivery_status_check');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['outbound_idempotency_key', 'delivery_status']);
        });
    }
};
