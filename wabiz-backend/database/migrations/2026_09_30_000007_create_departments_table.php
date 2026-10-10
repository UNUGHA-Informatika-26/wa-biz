<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'departments_organization_id_id_unique');
            $table->index('organization_id');
            $table->index('active');
            $table->index(['organization_id', 'active'], 'departments_organization_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
