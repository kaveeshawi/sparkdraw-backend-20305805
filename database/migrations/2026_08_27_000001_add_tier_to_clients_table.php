<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'tier')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->string('tier', 32)->nullable()->after('company_name')->index();
            });

            return;
        }

        $hasTierIndex = collect(Schema::getIndexes('clients'))->contains(
            fn (array $index) => in_array('tier', $index['columns'] ?? [], true)
        );

        if (! $hasTierIndex) {
            Schema::table('clients', function (Blueprint $table) {
                $table->index('tier');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('clients', 'tier')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('clients'));
            $tierIndex = $indexes->first(
                fn (array $index) => in_array('tier', $index['columns'] ?? [], true)
                    && count($index['columns'] ?? []) === 1
            );

            if ($tierIndex && ! empty($tierIndex['name'])) {
                $table->dropIndex($tierIndex['name']);
            }

            $table->dropColumn('tier');
        });
    }
};
