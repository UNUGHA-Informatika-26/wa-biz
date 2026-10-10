<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_handoff_notifications', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_number')->default(1);
            $table->json('recovery_history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_handoff_notifications', function (Blueprint $table): void {
            $table->dropColumn(['attempt_number', 'recovery_history']);
        });
    }
};
