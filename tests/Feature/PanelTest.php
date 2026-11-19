<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->has('projects', 0)
            ->has('servers', 0)
            ->has('deployments', 0));
    }

    public function test_panel_sections_can_be_opened_directly_by_url(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([
            '/' => 'summary',
            '/programaciones' => 'deployments',
            '/proyectos' => 'projects',
            '/servidores' => 'servers',
        ] as $url => $section) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page
                ->component('Panel')
                ->where('section', $section)
                ->where("sectionUrls.{$section}", $url));
        }
    }

    public function test_direct_section_links_require_authentication(): void
    {
        foreach (['/programaciones', '/proyectos', '/servidores'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_login_page_renders_as_an_inertia_page(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->has('errors'));
    }

    public function test_panel_shares_success_flash_messages(): void
    {
        $this->withSession(['success' => 'Proyecto registrado.'])
            ->actingAs(User::factory()->create())
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel')
                ->where('flash.success', 'Proyecto registrado.'));
    }

    public function test_panel_exposes_only_safe_project_properties(): void
    {
        $project = Project::create([
            'name' => 'Proyecto',
            'gitea_url' => 'https://gitea.test',
            'owner' => 'team',
            'repository' => 'app',
            'gitea_token' => 'secret-token',
            'preserved_paths' => [],
        ]);
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);

        $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->has('projects', 1, fn (Assert $projectPage) => $projectPage
                ->where('name', 'Proyecto')
                ->missing('gitea_token')
                ->has('servers', 1, fn (Assert $serverPage) => $serverPage
                    ->where('path', 'C:\\app')
                    ->etc())
                ->etc())
            ->has('servers', 1, fn (Assert $serverPage) => $serverPage
                ->where('name', 'Servidor')
                ->missing('agent_token_hash')
                ->etc()));
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

    private function scheduledDeployment(User $user, Project $project, Server $server): void
    {
        Http::fake(['*' => Http::response(['sha' => str_repeat('b', 40)])]);
        $project->servers()->syncWithoutDetaching([$server->id => ['path' => 'C:\\app']]);
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main',
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'UTC',
        ])->assertSessionHasNoErrors();
    }

    private function makeProject(): Project
    {
        return Project::create(['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => []]);
    }

    public function test_inactive_server_or_project_cannot_be_scheduled(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $data = ['project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main', 'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'UTC'];

        $this->actingAs($user)->post("/servers/{$server->id}/active", ['is_active' => false])->assertSessionHasNoErrors();
        $this->post('/deployments', $data)->assertSessionHasErrors('servers');

        $server->update(['is_active' => true]);
        $this->post("/projects/{$project->id}/active", ['is_active' => false]);
        $this->post('/deployments', $data)->assertSessionHasErrors('project_id');
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_server_and_project_with_history_cannot_be_deleted_but_unused_ones_can(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $this->scheduledDeployment($user, $project, $server);

        $this->delete("/servers/{$server->id}")->assertSessionHasErrors('server');
        $this->delete("/projects/{$project->id}")->assertSessionHasErrors('project');
        $this->assertDatabaseHas('servers', ['id' => $server->id]);

        $unused = Server::create(['name' => 'Libre']);
        $unusedProject = Project::create(['name' => 'Libre', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'x', 'preserved_paths' => []]);
        $unusedProject->servers()->attach($unused, ['path' => 'C:\\x']);
        $this->delete("/projects/{$unusedProject->id}")->assertSessionHasNoErrors();
        $this->delete("/servers/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('servers', ['id' => $unused->id]);
        $this->assertDatabaseMissing('projects', ['id' => $unusedProject->id]);
    }

    public function test_cancelled_deployment_can_be_deleted_and_frees_the_commit(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $this->scheduledDeployment($user, $project, $server);
        $deployment = Deployment::firstOrFail();

        $this->delete("/deployments/{$deployment->id}")->assertSessionHasErrors('deployment');
        $this->post("/deployments/{$deployment->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', DeploymentTarget::firstOrFail()->status);
        $this->post("/deployments/{$deployment->id}/cancel")->assertSessionHasErrors('deployment');

        $this->delete("/deployments/{$deployment->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('deployment_targets', 0);
    }

    public function test_assignment_in_use_cannot_be_removed(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $this->scheduledDeployment($user, $project, $server);

        $this->delete("/projects/{$project->id}/servers/{$server->id}")->assertSessionHasErrors('assignment');
        $this->post('/deployments/'.Deployment::firstOrFail()->id.'/cancel');
        $this->delete("/projects/{$project->id}/servers/{$server->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('project_server', 0);
    }

    public function test_agent_token_is_generated_from_the_panel_and_authenticates_the_agent(): void
    {
        $server = Server::create(['name' => 'Servidor']);
        $this->actingAs(User::factory()->create())->post("/servers/{$server->id}/token")->assertSessionHasNoErrors();
        $this->assertNotNull($server->fresh()->agent_token_hash);
        $flash = $this->get('/')->viewData('page')['flash']['agent_token'];
        $this->assertSame('Servidor', $flash['server']);
        $this->assertMatchesRegularExpression('/^'.$server->id.'\.[a-f0-9]{64}$/', $flash['token']);
        $this->assertArrayNotHasKey('flash', $this->get('/')->viewData('page'));

        $first = $server->fresh()->agent_token_hash;
        $this->post("/servers/{$server->id}/token")->assertSessionHasNoErrors();
        $this->assertNotSame($first, $server->fresh()->agent_token_hash);

        $server->forceFill(['active_target_id' => 1])->save();
        $this->post("/servers/{$server->id}/token")->assertSessionHasErrors('server');
    }

    public function test_agent_token_requires_authentication(): void
    {
        $server = Server::create(['name' => 'Servidor']);
        $this->post("/servers/{$server->id}/token")->assertRedirect('/login');
        $this->assertNull($server->fresh()->agent_token_hash);
    }
}
