<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['organization_id', 'id'], 'users_organization_id_id_unique');
        });

        Schema::create('cs_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('user_id');
            $table->foreignId('department_id')->nullable();
            $table->string('display_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'user_id', 'department_id'],
                'cs_members_org_user_department_unique',
            );
            $table->index('organization_id');
            $table->index('user_id');
            $table->index('department_id');
            $table->index('active');
            $table->index(
                ['organization_id', 'department_id', 'active'],
                'cs_members_org_department_active_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cs_members
            ADD CONSTRAINT cs_members_organization_user_foreign
            FOREIGN KEY (organization_id, user_id)
            REFERENCES users (organization_id, id)
            ON DELETE CASCADE
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE cs_members
            ADD CONSTRAINT cs_members_organization_department_foreign
            FOREIGN KEY (organization_id, department_id)
            REFERENCES departments (organization_id, id)
            ON DELETE SET NULL (department_id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX cs_members_org_user_without_department_unique
            ON cs_members (organization_id, user_id)
            WHERE department_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_members');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_organization_id_id_unique');
        });
    }
};
