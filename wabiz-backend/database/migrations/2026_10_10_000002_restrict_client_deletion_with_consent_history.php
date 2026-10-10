<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_whatsapp_consent_events', function (Blueprint $table): void {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('client_whatsapp_consent_events')->exists()) {
            throw new RuntimeException('Consent history exists; client deletion restriction cannot be rolled back safely.');
        }

        Schema::table('client_whatsapp_consent_events', function (Blueprint $table): void {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }
};