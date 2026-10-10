<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cs_members', function (Blueprint $table) {
            $table->unique(
                ['organization_id', 'id'],
                'cs_members_organization_id_id_unique',
            );
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable();
            $table->foreignId('cs_member_id')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->index(
                ['organization_id', 'department_id'],
                'conversations_org_department_index',
            );
            $table->index(
                ['organization_id', 'cs_member_id'],
                'conversations_org_cs_member_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_organization_department_foreign
            FOREIGN KEY (organization_id, department_id)
            REFERENCES departments (organization_id, id)
            ON DELETE SET NULL (department_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE conversations
            ADD CONSTRAINT conversations_organization_cs_member_foreign
            FOREIGN KEY (organization_id, cs_member_id)
            REFERENCES cs_members (organization_id, id)
            ON DELETE SET NULL (cs_member_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE conversations DROP CONSTRAINT conversations_organization_cs_member_foreign',
        );
        DB::statement(
            'ALTER TABLE conversations DROP CONSTRAINT conversations_organization_department_foreign',
        );

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_org_department_index');
            $table->dropIndex('conversations_org_cs_member_index');
            $table->dropColumn(['department_id', 'cs_member_id', 'assigned_at']);
        });

        Schema::table('cs_members', function (Blueprint $table) {
            $table->dropUnique('cs_members_organization_id_id_unique');
        });
    }
};
