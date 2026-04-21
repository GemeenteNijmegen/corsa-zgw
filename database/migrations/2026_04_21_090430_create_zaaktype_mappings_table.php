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
        Schema::create('zaaktype_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('catalogus_id')->constrained('catalogi')->cascadeOnDelete();
            $table->string('zaaktype_url')->unique();
            $table->string('zaaktype_identificatie')->default('');
            $table->string('zaaktype_omschrijving')->default('');
            $table->string('corsa_zaaktype_code', 20)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zaaktype_mappings');
    }
};
