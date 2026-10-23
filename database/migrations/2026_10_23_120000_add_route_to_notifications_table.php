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
        // Every existing notification went through a batch, so the default is batch.
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('route')->default('batch')->after('batch_id');
            $table->index('route');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['route']);
            $table->dropColumn('route');
        });
    }
};
