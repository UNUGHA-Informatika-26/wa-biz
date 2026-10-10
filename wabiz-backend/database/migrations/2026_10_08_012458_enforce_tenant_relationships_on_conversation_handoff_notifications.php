<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'conversations_organization_id_id_unique');
        });
        Schema::table('conversation_handoff_notifications', function (Blueprint $table): void {
            $table->dropForeign('conversation_handoff_notifications_conversation_id_foreign');
            $table->dropForeign('conversation_handoff_notifications_cs_member_id_foreign');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversation_handoff_notifications
            ADD CONSTRAINT handoff_notifications_organization_conversation_foreign
            FOREIGN KEY (organization_id, conversation_id)
            REFERENCES conversations (organization_id, id)
            ON DELETE CASCADE
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE conversation_handoff_notifications
            ADD CONSTRAINT handoff_notifications_organization_cs_member_foreign
            FOREIGN KEY (organization_id, cs_member_id)
            REFERENCES cs_members (organization_id, id)
            ON DELETE SET NULL (cs_member_id)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE conversation_handoff_notifications DROP CONSTRAINT handoff_notifications_organization_cs_member_foreign');
        DB::statement('ALTER TABLE conversation_handoff_notifications DROP CONSTRAINT handoff_notifications_organization_conversation_foreign');
        Schema::table('conversation_handoff_notifications', function (Blueprint $table): void {
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('cs_member_id')->references('id')->on('cs_members')->nullOnDelete();
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique('conversations_organization_id_id_unique');
        });
    }
};
