<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'clock_out_at']);
            $table->index(['agency_id', 'clock_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};
