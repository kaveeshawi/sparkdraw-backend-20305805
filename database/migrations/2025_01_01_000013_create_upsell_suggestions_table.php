<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upsell_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('service_type');
            $table->float('confidence')->default(0);
            $table->enum('admin_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->enum('client_status', ['hidden', 'shown', 'accepted'])->default('hidden');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upsell_suggestions');
    }
};
