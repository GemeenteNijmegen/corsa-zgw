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
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('processed_at');
            $table->string('failure', 255)->nullable()->after('failed_at');
            $table->string('failed_job_id')->nullable()->after('failure');
            $table->index('failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['failed_at']);
            $table->dropColumn(['failed_at', 'failure', 'failed_job_id']);
        });
    }
};
