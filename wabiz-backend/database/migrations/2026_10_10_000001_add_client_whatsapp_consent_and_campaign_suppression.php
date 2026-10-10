<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_whatsapp_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 16);
            $table->string('source', 100);
            $table->string('evidence_type', 100);
            $table->string('evidence_reference', 255);
            $table->timestampTz('recorded_at');
            $table->timestampsTz();
            $table->index(['client_id', 'id'], 'client_whatsapp_consent_latest_index');
        });

        DB::statement("ALTER TABLE client_whatsapp_consent_events ADD CONSTRAINT client_whatsapp_consent_action_check CHECK (action IN ('granted', 'withdrawn'))");

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('suppressed_count')->default(0);
        });
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->timestampTz('suppressed_at')->nullable();
        });
        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT IF EXISTS campaign_recipients_status_check');
        DB::statement("ALTER TABLE campaign_recipients ADD CONSTRAINT campaign_recipients_status_check CHECK (status IN ('pending', 'sending', 'sent', 'delivered', 'failed', 'unknown', 'suppressed'))");
    }

    public function down(): void
    {
        if (DB::table('client_whatsapp_consent_events')->exists()
            || DB::table('campaign_recipients')->where('status', 'suppressed')->exists()) {
            throw new RuntimeException('Consent history or suppressed recipients exist; this migration cannot be rolled back safely.');
        }

        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT IF EXISTS campaign_recipients_status_check');
        DB::statement("ALTER TABLE campaign_recipients ADD CONSTRAINT campaign_recipients_status_check CHECK (status IN ('pending', 'sending', 'sent', 'delivered', 'failed', 'unknown'))");
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn('suppressed_at');
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('suppressed_count');
        });
        Schema::dropIfExists('client_whatsapp_consent_events');
    }
};
