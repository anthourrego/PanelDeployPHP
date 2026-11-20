<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the latest synchronized SemVer tag and its sync status to projects.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('latest_tag')->nullable();
            $table->string('latest_tag_commit', 40)->nullable();
            $table->timestamp('latest_tag_synced_at')->nullable();
            $table->text('latest_tag_sync_error')->nullable();
        });
    }

    /**
     * Remove the synchronized tag metadata from projects.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'latest_tag',
                'latest_tag_commit',
                'latest_tag_synced_at',
                'latest_tag_sync_error',
            ]);
        });
    }
};
