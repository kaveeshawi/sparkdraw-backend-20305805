<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['agency_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_services');
    }
};
