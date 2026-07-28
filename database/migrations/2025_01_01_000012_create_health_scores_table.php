<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('score');
            $table->enum('flag', ['green', 'amber', 'red']);
            $table->json('reasons');
            $table->timestamp('computed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_scores');
    }
};
