<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('brand_colors');
            }
            if (! Schema::hasColumn('agencies', 'logo_dark_path')) {
                $table->string('logo_dark_path')->nullable()->after('logo_path');
            }
            if (! Schema::hasColumn('agencies', 'email')) {
                $table->string('email')->nullable()->after('domain_slug');
            }
            if (! Schema::hasColumn('agencies', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }
            if (! Schema::hasColumn('agencies', 'website')) {
                $table->string('website')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('agencies', 'address')) {
                $table->text('address')->nullable()->after('website');
            }
            if (! Schema::hasColumn('agencies', 'social_links')) {
                $table->json('social_links')->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['social_links', 'address', 'website', 'phone', 'email', 'logo_dark_path', 'currency'] as $column) {
                if (Schema::hasColumn('agencies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
