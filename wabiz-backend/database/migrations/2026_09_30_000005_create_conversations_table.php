<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();
            $table->string('status')->default('AI_ACTIVE');
            $table->string('channel');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('client_id');
            $table->index('status');
            $table->index('last_message_at');
            $table->index(
                ['organization_id', 'status', 'last_message_at'],
                'conversations_org_status_last_message_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_status_check
            CHECK (status IN ('AI_ACTIVE', 'WAITING_HUMAN', 'TRANSFERRED_TO_HUMAN', 'HUMAN_ACTIVE', 'RESOLVED', 'ARCHIVED'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
