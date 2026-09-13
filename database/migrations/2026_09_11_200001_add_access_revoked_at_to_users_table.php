<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'access_revoked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('access_revoked_at')->nullable()->after('avatar_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'access_revoked_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('access_revoked_at');
            });
        }
    }
};
