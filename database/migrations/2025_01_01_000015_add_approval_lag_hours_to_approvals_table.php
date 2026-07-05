<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            // Stores calculated lag in hours between requested_at and responded_at.
            // Read by C3 Health Score algorithm — do not remove.
            $table->unsignedInteger('approval_lag_hours')->nullable()->after('responded_at');
            // Track which PM requested the approval
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropForeign(['requested_by_id']);
            $table->dropColumn(['approval_lag_hours', 'requested_by_id']);
        });
    }
};
