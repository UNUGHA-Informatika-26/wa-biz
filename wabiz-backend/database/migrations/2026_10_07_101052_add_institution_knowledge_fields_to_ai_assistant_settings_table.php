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
        Schema::table('ai_assistant_settings', function (Blueprint $table): void {
            $table->unsignedInteger('student_count')->nullable();
            $table->unsignedInteger('caregiver_count')->nullable();
            $table->unsignedInteger('teacher_count')->nullable();
            $table->string('google_maps_url', 2048)->nullable();
            $table->text('additional_information')->nullable();
            $table->jsonb('faqs')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'student_count',
                'caregiver_count',
                'teacher_count',
                'google_maps_url',
                'additional_information',
                'faqs',
            ]);
        });
    }
};
