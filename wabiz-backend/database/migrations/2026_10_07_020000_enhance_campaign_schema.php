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
            $table->timestampTz('completed_at')->nullable();
        });
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->string('original_phone_number', 40)->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
        });
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_status_check');
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('draft', 'scheduled', 'processing', 'sent', 'partial', 'completed', 'failed', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_status_check');
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('draft', 'scheduled', 'processing', 'sent', 'partial', 'failed', 'cancelled'))");
        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn(['original_phone_number', 'failed_at', 'attempt_count']);
        });
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
