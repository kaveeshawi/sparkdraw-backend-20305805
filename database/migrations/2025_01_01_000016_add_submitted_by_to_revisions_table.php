<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            // The user (client) who submitted this revision round
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('revisions', function (Blueprint $table) {
            $table->dropForeign(['submitted_by_id']);
            $table->dropColumn('submitted_by_id');
        });
    }
};
