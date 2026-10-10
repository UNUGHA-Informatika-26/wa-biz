<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('whatsapp_connection_id')->nullable()
                ->after('client_id')->constrained('whatsapp_connections')->nullOnDelete();
            $table->unsignedInteger('history_generation')->default(1)->after('channel');
            $table->index(
                ['organization_id', 'whatsapp_connection_id', 'client_id', 'status'],
                'conversations_org_connection_client_status_index',
            );
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedInteger('history_generation')->default(1)->after('conversation_id');
            $table->index(
                ['conversation_id', 'history_generation', 'created_at', 'id'],
                'messages_conversation_generation_created_id_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_generation_created_id_index');
            $table->dropColumn('history_generation');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_org_connection_client_status_index');
            $table->dropConstrainedForeignId('whatsapp_connection_id');
            $table->dropColumn('history_generation');
        });
    }
};
