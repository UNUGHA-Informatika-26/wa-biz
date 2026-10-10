<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_delivery_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_delivery_status_check
            CHECK (delivery_status IS NULL OR delivery_status IN ('pending', 'sending', 'sent', 'failed', 'unknown'))
        SQL);
    }

    public function down(): void
    {
        if (DB::table('messages')->where('delivery_status', 'sending')->exists()) {
            throw new RuntimeException('Cannot roll back while AI message delivery attempts are still marked sending.');
        }

        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_delivery_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_delivery_status_check
            CHECK (delivery_status IS NULL OR delivery_status IN ('pending', 'sent', 'failed', 'unknown'))
        SQL);
    }
};
