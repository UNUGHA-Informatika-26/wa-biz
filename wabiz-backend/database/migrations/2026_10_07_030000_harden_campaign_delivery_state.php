<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('unknown_count')->default(0);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->timestampTz('sending_at')->nullable();
            $table->timestampTz('unknown_at')->nullable();
            $table->index(['status', 'sending_at'], 'campaign_recipients_stale_sending_index');
        });

        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT IF EXISTS campaign_recipients_status_check');
        DB::statement("ALTER TABLE campaign_recipients ADD CONSTRAINT campaign_recipients_status_check CHECK (status IN ('pending', 'sending', 'sent', 'delivered', 'failed', 'unknown'))");
    }

    public function down(): void
    {
        DB::table('campaign_recipients')->where('status', 'unknown')->update([
            'status' => 'failed',
            'failed_at' => DB::raw('COALESCE(failed_at, unknown_at, CURRENT_TIMESTAMP)'),
            'error' => DB::raw("COALESCE(error, 'delivery_outcome_unknown')"),
        ]);
        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT IF EXISTS campaign_recipients_status_check');
        DB::statement("ALTER TABLE campaign_recipients ADD CONSTRAINT campaign_recipients_status_check CHECK (status IN ('pending', 'sending', 'sent', 'delivered', 'failed'))");

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropIndex('campaign_recipients_stale_sending_index');
            $table->dropColumn(['sending_at', 'unknown_at']);
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('unknown_count');
        });
    }
};
