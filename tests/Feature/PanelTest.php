<?php

namespace Tests\Feature;

use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee('Validación pendiente');
    }

    public function test_project_secrets_require_https_and_paths_must_be_relative(): void
    {
        $user = User::factory()->create();
        $data = ['name' => 'Proyecto', 'gitea_url' => 'http://gitea.test', 'owner' => 'team', 'repository' => 'app', 'gitea_token' => 'secret'];
        $this->actingAs($user)->post('/projects', $data)->assertSessionHasErrors('gitea_token');
        $data['gitea_token'] = null;
        $data['preserved_paths'] = '../outside';
        $this->actingAs($user)->post('/projects', $data)->assertSessionHasErrors('preserved_paths');
        $data['preserved_paths'] = "uploads\napplication/config/database.php";
        $this->actingAs($user)->post('/projects', $data)->assertSessionHasNoErrors();
        $this->assertSame(['uploads', 'application/config/database.php'], Project::firstOrFail()->preserved_paths);
    }

    public function test_programming_pins_commit_and_snapshots_each_target(): void
    {
        Http::fake(['*' => Http::response(['sha' => str_repeat('a', 40)])]);
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => ['uploads']]);
        $server = Server::create(['name' => 'Servidor']);
        $this->actingAs($user)->post('/assignments', ['project_id' => $project->id, 'server_id' => $server->id, 'path' => 'C:\\wamp64\\www\\app'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main',
            'scheduled_at' => now('America/Bogota')->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deployments', ['commit' => str_repeat('a', 40), 'ref' => 'main']);
        $this->assertDatabaseHas('deployment_targets', ['server_id' => $server->id, 'status' => 'pending', 'path' => 'C:\\wamp64\\www\\app']);
        $this->assertSame(['uploads'], DeploymentTarget::firstOrFail()->preserved_paths);
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main',
            'scheduled_at' => now('America/Bogota')->addDays(2)->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ])->assertSessionHasErrors('servers');
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_unknown_ref_or_unassigned_server_does_not_create_deployment(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $project = Project::create(['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => []]);
        $server = Server::create(['name' => 'Servidor']);
        $data = ['project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'missing', 'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'UTC'];
        $this->actingAs(User::factory()->create())->post('/deployments', $data)->assertSessionHasErrors('servers');
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $this->post('/deployments', $data)->assertSessionHasErrors('ref');
        $this->assertDatabaseCount('deployments', 0);
    }
}
