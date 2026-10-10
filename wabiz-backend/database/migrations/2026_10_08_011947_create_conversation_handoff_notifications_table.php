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
        Schema::create('conversation_handoff_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cs_member_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('history_generation');
            $table->string('notification_type', 50);
            $table->string('recipient_phone', 32)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('external_message_id')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('unknown_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['conversation_id', 'history_generation', 'notification_type'],
                'handoff_notifications_conversation_generation_type_unique',
            );
            $table->index(['organization_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_handoff_notifications');
    }
};
