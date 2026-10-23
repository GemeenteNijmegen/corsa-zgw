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
        Schema::create('document_syncs', function (Blueprint $table) {
            $table->id();
            // The ZGW standard allows URLs of up to 1000 characters.
            $table->string('document_url', 1000)->unique();
            $table->string('zaak_identificatie')->index();
            $table->string('corsa_identificatie');
            $table->unsignedInteger('versie')->nullable();
            $table->boolean('placeholder')->default(false);
            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_syncs');
    }
};
