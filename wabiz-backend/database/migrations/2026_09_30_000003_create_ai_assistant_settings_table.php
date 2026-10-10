<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                ->unique()
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->boolean('enabled')->default(false);
            $table->string('assistant_name')->default('Assistant WABiz');
            $table->string('communication_style')->default('friendly');
            $table->string('language')->default('indonesian');
            $table->text('additional_instructions')->nullable();

            $table->string('institution_name')->nullable();
            $table->text('institution_description')->nullable();
            $table->text('services')->nullable();
            $table->text('address')->nullable();
            $table->string('service_hours')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('location_description')->nullable();

            $table->boolean('registration_enabled')->default(false);
            $table->string('registration_url')->nullable();
            $table->string('registration_status')->nullable();
            $table->string('registration_cs_phone')->nullable();
            $table->text('registration_description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_settings');
    }
};
