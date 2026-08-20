<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department')->nullable()->after('email');
            $table->string('employment_type')->nullable()->after('department'); // full_time|part_time|contractor
            $table->string('availability')->default('offline')->after('employment_type'); // available|away|busy|offline
            $table->string('phone')->nullable()->after('availability');
            $table->string('job_title')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'department',
                'employment_type',
                'availability',
                'phone',
                'job_title',
            ]);
        });
    }
};
