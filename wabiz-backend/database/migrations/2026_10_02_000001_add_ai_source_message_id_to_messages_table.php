<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('ai_source_message_id')
                ->nullable()
                ->unique('messages_ai_source_message_id_unique')
                ->constrained('messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['ai_source_message_id']);
            $table->dropUnique('messages_ai_source_message_id_unique');
            $table->dropColumn('ai_source_message_id');
        });
    }
};
