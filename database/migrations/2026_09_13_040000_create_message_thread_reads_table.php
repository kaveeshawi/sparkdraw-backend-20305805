<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_thread_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // 0 = project-wide ("overall") thread; otherwise the DM peer user id
            $table->unsignedBigInteger('peer_user_id')->default(0);
            $table->timestamp('last_read_at');
            $table->timestamps();

            $table->unique(['user_id', 'project_id', 'peer_user_id'], 'message_thread_reads_unique');
            $table->index(['agency_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_thread_reads');
    }
};
