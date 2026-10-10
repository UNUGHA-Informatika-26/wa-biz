<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('provider', 100);
            $table->string('display_name');
            $table->string('phone_number');
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('status', 30)->default('disconnected');
            $table->boolean('is_primary')->default(false);
            $table->text('credentials')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'phone_number_id']);
            $table->index(['organization_id', 'enabled', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE whatsapp_connections
            ADD CONSTRAINT whatsapp_connections_status_check
            CHECK (status IN ('disconnected', 'connected', 'error'))
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX whatsapp_connections_one_primary_per_organization_unique
            ON whatsapp_connections (organization_id)
            WHERE is_primary = true
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
