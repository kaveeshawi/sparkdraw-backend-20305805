<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('icon', 32)->default('files');
            $table->string('visibility', 32)->default('only_me'); // only_me | agency | roles
            $table->json('roles')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->boolean('allow_upload')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'deleted_at']);
        });

        Schema::create('drive_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('drive_folders')->cascadeOnDelete();
            $table->foreignId('uploader_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index(['agency_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_files');
        Schema::dropIfExists('drive_folders');
    }
};
