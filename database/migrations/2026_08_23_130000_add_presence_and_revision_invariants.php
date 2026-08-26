<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(0);
        });

        Schema::table('session_users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->index();
        });

        // Existing active collaborators should remain active immediately after an upgrade.
        DB::table('session_users')
            ->whereNull('left_at')
            ->whereNull('last_seen_at')
            ->update(['last_seen_at' => DB::raw('COALESCE(joined_at, CURRENT_TIMESTAMP)')]);

        // The constraint applies only while a visual identifier is active.
        // PostgreSQL (production) and SQLite (local/testing) both support partial indexes.
        DB::statement('CREATE UNIQUE INDEX session_users_active_circuit_display_name_unique ON session_users (circuit_id, display_name) WHERE left_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX session_users_active_circuit_display_name_unique');

        Schema::table('session_users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });

        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
