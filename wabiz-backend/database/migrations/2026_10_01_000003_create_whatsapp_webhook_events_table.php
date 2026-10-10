<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->foreignId('whatsapp_connection_id')
                ->nullable()
                ->constrained('whatsapp_connections')
                ->nullOnDelete();
            $table->string('provider', 100);
            $table->string('external_event_id');
            $table->string('event_type', 100);
            $table->jsonb('payload');
            $table->string('status', 30)->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE whatsapp_webhook_events
            ADD CONSTRAINT whatsapp_webhook_events_status_check
            CHECK (status IN ('received', 'processed', 'failed'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
    }
};
