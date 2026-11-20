<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
            '/descargas' => 'downloads',
        ] as $url => $section) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page
                ->component('Panel')
                ->where('section', $section)
                ->where("sectionUrls.{$section}", $url));
        }
    }

    public function test_panel_exposes_only_the_authenticated_users_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Usuario de prueba', 'email' => 'usuario@example.test']);

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->has('user', fn (Assert $userPage) => $userPage
                ->where('name', 'Usuario de prueba')
                ->where('email', 'usuario@example.test')));
    }

    public function test_direct_section_links_require_authentication(): void
    {
        foreach (['/programaciones', '/proyectos', '/servidores', '/descargas'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_windows_agent_downloads_require_authentication(): void
    {
        Storage::fake('local')->put('agent/win-x64/PanelDeploySetup.exe', 'installer fixture');

        $this->get('/servidores/descargas/windows/installer')->assertRedirect('/login');
    }

    public function test_downloads_section_offers_published_windows_executables_separately_from_servers(): void
    {
        $disk = Storage::fake('local');
        $disk->put('agent/win-x64/PanelDeploySetup.exe', 'installer fixture');
        $disk->put('agent/win-x64/PanelDeployAgent.exe', 'agent fixture');
        config(['services.windows_agent.version' => '1.2.3']);
        $this->actingAs(User::factory()->create());

        $this->get('/servidores')->assertInertia(fn (Assert $page) => $page->where('agentDownloads', null));

        $this->get('/descargas')->assertInertia(fn (Assert $page) => $page
            ->where('agentDownloads.version', '1.2.3')
            ->where('agentDownloads.installer', '/servidores/descargas/windows/installer')
            ->where('agentDownloads.agent', '/servidores/descargas/windows/agent'));

        foreach (['installer' => 'PanelDeploySetup.exe', 'agent' => 'PanelDeployAgent.exe'] as $package => $name) {
            $response = $this->get('/servidores/descargas/windows/'.$package)
                ->assertOk()
                ->assertDownload($name);

            $this->assertSame($package.' fixture', $response->baseResponse->getFile()->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
    }

    public function test_unpublished_windows_executables_and_unknown_packages_are_not_downloadable(): void
    {
        $disk = Storage::fake('local');
        $disk->put('agent/win-x64/PanelDeployAgent.exe', 'agent fixture');
        $disk->put('agent/win-x64/config.json', 'private fixture');
        $this->actingAs(User::factory()->create());

        $this->get('/descargas')->assertInertia(fn (Assert $page) => $page
            ->where('agentDownloads.installer', null)
            ->where('agentDownloads.agent', '/servidores/descargas/windows/agent'));
        $this->get('/servidores/descargas/windows/installer')->assertNotFound();
        $this->get('/servidores/descargas/windows/config.json')->assertNotFound();
    }

    public function test_powershell_zip_is_downloadable_only_after_publication_and_login(): void
    {
        $disk = Storage::fake('local');
        $url = '/servidores/descargas/windows/powershell';

        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get($url)->assertNotFound();
        $this->get('/descargas')->assertInertia(fn (Assert $page) => $page->where('agentDownloads.powershell', null));

        $disk->put('agent/win-x64/PanelDeployPowerShell.zip', 'zip fixture');

        $this->get('/descargas')->assertInertia(fn (Assert $page) => $page->where('agentDownloads.powershell', $url));
        $response = $this->get($url)->assertOk()
            ->assertDownload('PanelDeployPowerShell.zip')
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertSame('zip fixture', $response->baseResponse->getFile()->getContent());
    }

    public function test_login_page_renders_as_an_inertia_page(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->has('errors'));
    }

    public function test_panel_shares_success_flash_messages(): void
    {
        $this->withSession(['success' => 'Proyecto registrado.', 'error' => 'No se pudieron sincronizar los tags.'])
            ->actingAs(User::factory()->create())
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel')
                ->where('flash.success', 'Proyecto registrado.')
                ->where('flash.error', 'No se pudieron sincronizar los tags.'));
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
        Http::preventStrayRequests();
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

    public function test_project_creation_syncs_the_highest_semver_tag_across_gitea_pages(): void
    {
        $firstPage = [
            ['name' => 'v1.9.9', 'commit' => ['sha' => str_repeat('a', 40)]],
            ['name' => 'v2.0.0-rc.2', 'commit' => ['sha' => str_repeat('b', 40)]],
            ['name' => 'v2.0.0-rc.10', 'commit' => ['sha' => str_repeat('c', 40)]],
        ];
        foreach (range(1, 47) as $patch) {
            $firstPage[] = ['name' => 'v1.0.'.$patch, 'commit' => ['sha' => str_repeat('d', 40)]];
        }
        $secondPage = [['name' => 'v2.0.0', 'commit' => ['sha' => str_repeat('e', 40)]]];

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) use ($firstPage, $secondPage) {
            return str_contains($request->url(), 'page=1')
                ? Http::response($firstPage)
                : Http::response($secondPage);
        });

        $this->actingAs(User::factory()->create())->post('/projects', [
            'name' => 'Proyecto',
            'gitea_url' => 'https://gitea.test',
            'owner' => 'team',
            'repository' => 'app',
            'gitea_token' => 'secret-token',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Proyecto registrado. Última versión disponible: v2.0.0.');

        $project = Project::firstOrFail();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_tag' => 'v2.0.0',
            'latest_tag_commit' => str_repeat('e', 40),
            'latest_tag_sync_error' => null,
        ]);
        $this->assertNotNull($project->latest_tag_synced_at);
        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'page=2')
            && $request->hasHeader('Authorization', 'token secret-token'));
    }

    public function test_manual_tag_sync_updates_the_project_version(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/tags*' => Http::response([
                ['name' => 'v3.0.0-rc.2', 'commit' => ['sha' => str_repeat('e', 40)]],
                ['name' => 'v3.0.0-rc.10', 'commit' => ['sha' => str_repeat('f', 40)]],
            ]),
        ]);
        $project = $this->makeProject();

        $this->actingAs(User::factory()->create())
            ->post("/projects/{$project->id}/tags/sync")
            ->assertSessionHas('success', 'Sincronización de tags completada. Última versión disponible: v3.0.0-rc.10.');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_tag' => 'v3.0.0-rc.10',
            'latest_tag_commit' => str_repeat('f', 40),
            'latest_tag_sync_error' => null,
        ]);
    }

    public function test_sync_with_no_semver_tags_clears_the_previous_version(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/tags*' => Http::response([
                ['name' => 'release-v1', 'commit' => ['sha' => str_repeat('e', 40)]],
            ]),
        ]);
        $project = $this->makeProject();
        $project->forceFill([
            'latest_tag' => 'v1.0.0',
            'latest_tag_commit' => str_repeat('a', 40),
        ])->save();

        $this->actingAs(User::factory()->create())
            ->post("/projects/{$project->id}/tags/sync")
            ->assertSessionHas('success', 'Sincronización de tags completada. No se encontraron tags SemVer.');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_tag' => null,
            'latest_tag_commit' => null,
            'latest_tag_sync_error' => null,
        ]);
        $this->assertNotNull($project->fresh()->latest_tag_synced_at);
    }

    public function test_failed_tag_sync_preserves_the_last_known_version_and_reports_the_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/tags*' => Http::response([], 503),
        ]);
        $project = $this->makeProject();
        $project->forceFill([
            'latest_tag' => 'v2.4.0',
            'latest_tag_commit' => str_repeat('a', 40),
        ])->save();

        $this->actingAs(User::factory()->create())
            ->post("/projects/{$project->id}/tags/sync")
            ->assertSessionHas('error', 'No fue posible sincronizar los tags. Revise el estado del proyecto.');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_tag' => 'v2.4.0',
            'latest_tag_commit' => str_repeat('a', 40),
            'latest_tag_sync_error' => 'No fue posible consultar los tags. Verifique la conexión, las credenciales y la URL del repositorio.',
        ]);
    }

    public function test_invalid_tag_response_preserves_the_last_known_version(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/tags*' => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ]);
        $project = $this->makeProject();
        $project->forceFill([
            'latest_tag' => 'v2.4.0',
            'latest_tag_commit' => str_repeat('a', 40),
        ])->save();

        $this->actingAs(User::factory()->create())
            ->post("/projects/{$project->id}/tags/sync")
            ->assertSessionHas('error', 'No fue posible sincronizar los tags. Revise el estado del proyecto.');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_tag' => 'v2.4.0',
            'latest_tag_commit' => str_repeat('a', 40),
            'latest_tag_sync_error' => 'No fue posible consultar los tags. Verifique la conexión, las credenciales y la URL del repositorio.',
        ]);
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
        $deployment = Deployment::firstOrFail();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_deployment_id' => $deployment->id,
        ]);
        $this->assertDatabaseHas('deployments', ['commit' => str_repeat('a', 40), 'ref' => 'main']);
        $this->assertDatabaseHas('deployment_targets', ['server_id' => $server->id, 'status' => 'pending', 'path' => 'C:\\wamp64\\www\\app']);
        $this->assertSame(['uploads'], DeploymentTarget::firstOrFail()->preserved_paths);
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main',
            'scheduled_at' => now('America/Bogota')->addDays(2)->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ])->assertSessionHasErrors('servers');
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_project_shows_its_latest_deployment_and_target_statuses(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $this->scheduledDeployment($user, $project, $server);
        DeploymentTarget::firstOrFail()->forceFill(['status' => 'succeeded'])->save();

        $this->actingAs($user)->get('/proyectos')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->has('projects', 1, fn (Assert $projectPage) => $projectPage
                ->where('latest_deployment.ref', 'main')
                ->where('latest_deployment.commit', str_repeat('b', 40))
                ->where('latest_deployment.targets.0.server_name', 'Servidor')
                ->where('latest_deployment.targets.0.status', 'succeeded')
                ->etc()));
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

    public function test_deleting_the_latest_cancelled_deployment_restores_the_previous_latest_deployment(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $this->scheduledDeployment($user, $project, $server);
        $previousDeployment = Deployment::firstOrFail();
        $this->post("/deployments/{$previousDeployment->id}/cancel")->assertSessionHasNoErrors();

        $this->scheduledDeployment($user, $project, $server);
        $latestDeployment = Deployment::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_deployment_id' => $latestDeployment->id,
        ]);
        $this->post("/deployments/{$latestDeployment->id}/cancel")->assertSessionHasNoErrors();
        $this->delete("/deployments/{$latestDeployment->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_deployment_id' => $previousDeployment->id,
        ]);
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
