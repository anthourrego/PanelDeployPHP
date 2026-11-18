<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('gitea_url');
            $table->string('owner');
            $table->string('repository');
            $table->text('gitea_token')->nullable();
            $table->json('preserved_paths');
            $table->timestamps();
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('project_server', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->noActionOnDelete();
            $table->foreignId('server_id')->constrained()->noActionOnDelete();
            $table->string('path');
            $table->unique(['project_id', 'server_id']);
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->noActionOnDelete();
            $table->foreignId('user_id')->constrained()->noActionOnDelete();
            $table->string('ref');
            $table->string('commit', 40);
            $table->timestamp('scheduled_at');
            $table->timestamps();
        });

        Schema::create('deployment_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->noActionOnDelete();
            $table->foreignId('server_id')->constrained()->noActionOnDelete();
            $table->string('path');
            $table->json('preserved_paths');
            $table->string('status')->default('pending');
            $table->text('result')->nullable();
            $table->timestamps();
            $table->unique(['deployment_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_targets');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('project_server');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('projects');
    }
};
