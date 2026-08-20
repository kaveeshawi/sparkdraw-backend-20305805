<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('provider'); // mail_smtp|google_meet|microsoft_teams|stripe
            $table->string('status')->default('disconnected'); // connected|disconnected
            $table->text('credentials')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->unique(['agency_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_integrations');
    }
};
