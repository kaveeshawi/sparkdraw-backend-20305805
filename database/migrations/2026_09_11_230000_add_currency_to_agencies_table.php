<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'currency')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('currency', 3)->default('USD')->after('brand_colors');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'currency')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
