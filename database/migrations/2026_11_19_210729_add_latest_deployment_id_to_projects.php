<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('latest_deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
        });

        $latestDeployments = DB::table('deployments')
            ->select('project_id')
            ->selectRaw('MAX(id) as latest_deployment_id')
            ->groupBy('project_id')
            ->get();

        foreach ($latestDeployments as $deployment) {
            DB::table('projects')
                ->where('id', $deployment->project_id)
                ->update(['latest_deployment_id' => $deployment->latest_deployment_id]);
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['latest_deployment_id']);
            $table->dropColumn('latest_deployment_id');
        });
    }
};
