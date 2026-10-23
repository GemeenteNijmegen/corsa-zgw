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
            $table->timestamp('ignored_at')->nullable()->after('processed_at');
            $table->string('ignored_reason')->nullable()->after('ignored_at');
            $table->index('ignored_reason');
        });

        // A notification that is ignored before the zaak is fetched has no zaak identificatie.
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('zaak_identificatie')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // zaak_identificatie stays nullable: rows without one may exist by now.
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['ignored_reason']);
            $table->dropColumn(['ignored_at', 'ignored_reason']);
        });
    }
};
