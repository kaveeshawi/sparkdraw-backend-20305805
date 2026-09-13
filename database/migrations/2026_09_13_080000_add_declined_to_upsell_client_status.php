<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE upsell_suggestions MODIFY COLUMN client_status ENUM('hidden', 'shown', 'accepted', 'declined') NOT NULL DEFAULT 'hidden'");
        }
        // SQLite stores enums as strings — no schema change required.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::table('upsell_suggestions')
                ->where('client_status', 'declined')
                ->update(['client_status' => 'shown']);

            DB::statement("ALTER TABLE upsell_suggestions MODIFY COLUMN client_status ENUM('hidden', 'shown', 'accepted') NOT NULL DEFAULT 'hidden'");
        }
    }
};
