<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->uuid('gateway_session_ref')->nullable()->unique();
        });

        DB::statement('ALTER TABLE whatsapp_connections DROP CONSTRAINT whatsapp_connections_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE whatsapp_connections
            ADD CONSTRAINT whatsapp_connections_status_check
            CHECK (status IN ('disconnected', 'connecting', 'qr_ready', 'connected', 'reconnecting', 'error'))
        SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE whatsapp_connections SET status = 'disconnected' WHERE status IN ('connecting', 'qr_ready', 'reconnecting')");
        DB::statement('ALTER TABLE whatsapp_connections DROP CONSTRAINT whatsapp_connections_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE whatsapp_connections
            ADD CONSTRAINT whatsapp_connections_status_check
            CHECK (status IN ('disconnected', 'connected', 'error'))
        SQL);

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropUnique(['gateway_session_ref']);
            $table->dropColumn('gateway_session_ref');
        });
    }
};
