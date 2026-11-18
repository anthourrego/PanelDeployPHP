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
            $table->string('ssh_url')->nullable();
        });
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('repository_url')->nullable();
        });
        $deployments = DB::table('deployments')->join('projects', 'projects.id', '=', 'deployments.project_id')
            ->select('deployments.id', 'projects.gitea_url', 'projects.owner', 'projects.repository')->get();
        foreach ($deployments as $deployment) {
            DB::table('deployments')->where('id', $deployment->id)->update([
                'repository_url' => $deployment->gitea_url.'/'.$deployment->owner.'/'.$deployment->repository.'.git',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('deployments', fn (Blueprint $table) => $table->dropColumn('repository_url'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('ssh_url'));
    }
};
