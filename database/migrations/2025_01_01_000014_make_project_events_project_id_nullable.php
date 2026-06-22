<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// project_events can record agency-level events (user_invited, user_role_changed)
// that are not tied to a specific project, so project_id must be nullable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_events', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_events', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }
};
