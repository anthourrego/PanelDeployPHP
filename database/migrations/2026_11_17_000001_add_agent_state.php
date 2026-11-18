<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('agent_token_hash', 64)->nullable();
            $table->unsignedBigInteger('active_target_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
        });
        Schema::table('deployment_targets', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployment_targets', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'finished_at']);
        });
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['agent_token_hash', 'active_target_id', 'last_seen_at']);
        });
    }
};
