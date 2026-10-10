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
        Schema::table('cs_members', function (Blueprint $table): void {
            // A CS contact may be managed before they have an application login.
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('ai_assistant_settings', function (Blueprint $table): void {
            $table->foreignId('registration_department_id')->nullable();
            $table->unique(
                ['organization_id', 'registration_department_id'],
                'ai_settings_org_registration_department_unique',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_assistant_settings
            ADD CONSTRAINT ai_settings_registration_department_same_org_foreign
            FOREIGN KEY (organization_id, registration_department_id)
            REFERENCES departments (organization_id, id)
            ON DELETE SET NULL (registration_department_id)
        SQL);

        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('handoff_reason', 100)->nullable();
            $table->timestamp('handoff_requested_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            'ALTER TABLE ai_assistant_settings DROP CONSTRAINT ai_settings_registration_department_same_org_foreign',
        );

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['handoff_reason', 'handoff_requested_at']);
        });

        Schema::table('ai_assistant_settings', function (Blueprint $table): void {
            $table->dropUnique('ai_settings_org_registration_department_unique');
            $table->dropColumn('registration_department_id');
        });

        // Keep user_id nullable on rollback to avoid data loss/failure if
        // contacts without logins have been added after this migration.
    }
};
