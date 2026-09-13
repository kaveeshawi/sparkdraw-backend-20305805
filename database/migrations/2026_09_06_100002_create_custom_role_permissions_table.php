<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 60);
            $table->boolean('allowed')->default(false);
            $table->timestamps();

            $table->unique(['custom_role_id', 'permission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_role_permissions');
    }
};
