<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_services', function (Blueprint $table) {
            $table->unsignedInteger('default_hours')->nullable()->after('description');
            $table->decimal('default_budget', 12, 2)->nullable()->after('default_hours');
            $table->json('suggested_roles')->nullable()->after('default_budget');
        });
    }

    public function down(): void
    {
        Schema::table('agency_services', function (Blueprint $table) {
            $table->dropColumn(['default_hours', 'default_budget', 'suggested_roles']);
        });
    }
};
