<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('deployment_targets')
            ->join('deployments', 'deployments.id', '=', 'deployment_targets.deployment_id')
            ->whereIn('deployment_targets.status', ['pending', 'running', 'uncertain'])
            ->select('deployments.project_id', 'deployment_targets.server_id', 'deployments.commit')
            ->groupBy('deployments.project_id', 'deployment_targets.server_id', 'deployments.commit')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('Hay programaciones activas duplicadas; revíselas antes de aplicar esta migración.');
        }
        Schema::create('deployment_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->noActionOnDelete();
            $table->foreignId('server_id')->constrained()->noActionOnDelete();
            $table->foreignId('deployment_target_id')->unique()->constrained()->noActionOnDelete();
            $table->string('commit', 40);
            $table->unique(['project_id', 'server_id', 'commit']);
        });

        $targets = DB::table('deployment_targets')
            ->join('deployments', 'deployments.id', '=', 'deployment_targets.deployment_id')
            ->whereIn('deployment_targets.status', ['pending', 'running', 'uncertain'])
            ->select('deployment_targets.id', 'deployment_targets.server_id', 'deployments.project_id', 'deployments.commit')
            ->get();
        foreach ($targets as $target) {
            DB::table('deployment_reservations')->insert([
                'project_id' => $target->project_id,
                'server_id' => $target->server_id,
                'deployment_target_id' => $target->id,
                'commit' => $target->commit,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_reservations');
    }
};
