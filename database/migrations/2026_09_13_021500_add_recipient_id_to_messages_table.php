<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // null = project-wide (everyone) thread; set = 1:1 DM with that user
            $table->foreignId('recipient_id')
                ->nullable()
                ->after('sender_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['project_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_id');
        });
    }
};
