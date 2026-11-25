<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureActiveUser;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
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

    public function test_login_uses_request_base_for_assets_and_actions_in_a_subdirectory(): void
    {
        config(['app.url' => 'https://panel.example.test']);
        $this->withoutVite()->withServerVariables([
            'SCRIPT_NAME' => '/PanelDeploy/public/index.php',
            'SCRIPT_FILENAME' => 'index.php',
        ]);

        $this->get('http://localhost/PanelDeploy/public/login')->assertOk()
            ->assertSee('href="http://localhost/PanelDeploy/public/favicon.ico"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('basePath', '/PanelDeploy/public')
                ->where('logoUrl', 'http://localhost/PanelDeploy/public/img/prosof-logo.webp'));
    }

    public function test_panel_links_keep_the_subdirectory(): void
    {
        $project = $this->makeProject();
        $user = User::factory()->admin()->create();
        $this->withServerVariables([
            'SCRIPT_NAME' => '/PanelDeploy/public/index.php',
            'SCRIPT_FILENAME' => 'index.php',
        ])->actingAs($user);

        $this->get('http://localhost/PanelDeploy/public/proyectos')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('basePath', '/PanelDeploy/public')
                ->where('sectionUrls.projects', 'http://localhost/PanelDeploy/public/proyectos')
                ->where('profileUpdateUrl', 'http://localhost/PanelDeploy/public/perfil')
                ->where('passwordUpdateUrl', 'http://localhost/PanelDeploy/public/perfil/contrasena')
                ->where('projectTagsSyncAllUrl', 'http://localhost/PanelDeploy/public/projects/tags/sync-all')
                ->where('projects.0.references_url', "http://localhost/PanelDeploy/public/projects/{$project->id}/references"));

        $this->get('http://localhost/PanelDeploy/public/usuarios')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.0.update_url', "http://localhost/PanelDeploy/public/usuarios/{$user->id}")
                ->where('users.0.active_url', "http://localhost/PanelDeploy/public/usuarios/{$user->id}/estado"));
    }

    public function test_agent_download_link_keeps_the_subdirectory(): void
    {
        Storage::fake('local')->put('agent/win-x64/PanelDeploySetup.exe', 'installer fixture');
        $this->withServerVariables([
            'SCRIPT_NAME' => '/PanelDeploy/public/index.php',
            'SCRIPT_FILENAME' => 'index.php',
        ])->actingAs(User::factory()->create());

        $this->get('http://localhost/PanelDeploy/public/descargas')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('agentDownloads.installer', 'http://localhost/PanelDeploy/public/servidores/descargas/windows/installer'));
    }

    public function test_front_controller_is_kept_for_actions_but_not_for_assets(): void
    {
        $this->withoutVite()->withServerVariables([
            'SCRIPT_NAME' => '/PanelDeploy/public/index.php',
            'SCRIPT_FILENAME' => 'index.php',
        ]);

        $this->get('http://localhost/PanelDeploy/public/index.php/login')->assertOk()
            ->assertSee('href="http://localhost/PanelDeploy/public/favicon.ico"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('basePath', '/PanelDeploy/public/index.php')
                ->where('logoUrl', 'http://localhost/PanelDeploy/public/img/prosof-logo.webp'));
    }

    public function test_login_assets_and_actions_keep_root_urls(): void
    {
        $this->withoutVite()->get('/login')->assertOk()
            ->assertSee('href="http://localhost/favicon.ico"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('basePath', '')
                ->where('logoUrl', 'http://localhost/img/prosof-logo.webp'));
    }

    public function test_panel_exposes_the_configured_deployment_timezone(): void
    {
        config(['app.deployment_timezone' => 'America/New_York']);

        $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->where('deploymentTimezone', 'America/New_York')
            ->etc());
    }

    public function test_panel_exposes_its_configured_version_without_running_git(): void
    {
        config(['app.version' => '2.3.4']);
        Process::fake();

        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('appVersion', '2.3.4'));

        Process::assertNothingRan();
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
                ->where("sectionUrls.{$section}", 'http://localhost'.rtrim($url, '/')));
        }
    }

    public function test_deployment_history_filters_by_project_target_and_inclusive_utc_dates(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $otherProject = $this->makeProject(['name' => 'Otro proyecto', 'repository' => 'other']);
        $first = Server::create(['name' => 'Primero']);
        $second = Server::create(['name' => 'Segundo']);
        $record = function (Project $owner, string $date, array $statuses) use ($user): Deployment {
            $deployment = $owner->deployments()->create([
                'user_id' => $user->id,
                'ref' => 'main',
                'commit' => str_repeat('a', 40),
                'scheduled_at' => $date,
            ]);
            foreach ($statuses as $serverId => $status) {
                $deployment->targets()->create([
                    'server_id' => $serverId,
                    'path' => 'C:\\app',
                    'preserved_paths' => [],
                ])->forceFill(['status' => $status])->save();
            }

            return $deployment;
        };
        $record($project, '2026-03-01 12:00:00', [$first->id => 'pending', $second->id => 'failed']);
        $start = $record($project, '2026-03-01 00:00:00', [$first->id => 'failed']);
        $end = $record($project, '2026-03-02 23:59:59', [$first->id => 'failed', $second->id => 'pending']);
        $record($otherProject, '2026-03-01 12:00:00', [$first->id => 'failed']);
        $record($project, '2026-02-28 23:59:59', [$first->id => 'failed']);
        $record($project, '2026-03-03 00:00:00', [$first->id => 'failed']);
        $record($project, '2026-03-01 12:00:00', [$first->id => 'pending']);

        $this->actingAs($user)->get('/programaciones?'.http_build_query([
            'project_id' => $project->id,
            'server_id' => $first->id,
            'status' => 'failed',
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-02',
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('deploymentHistory.total', 2)
            ->has('deploymentHistory.data', 2)
            ->where('deploymentHistory.data.0.id', $end->id)
            ->has('deploymentHistory.data.0.targets', 2)
            ->where('deploymentHistory.data.0.targets.1.status', 'pending')
            ->where('deploymentHistory.data.1.id', $start->id));

        $this->get("/programaciones?project_id={$project->id}")->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 6));
        $this->get("/programaciones?server_id={$second->id}")->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 2));
        $this->get('/programaciones?status=failed')->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 6));
        $this->get('/programaciones?date_to=2026-03-01')->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 5));
        $this->get('/programaciones?date_from=2026-03-03')->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 1));
    }

    public function test_cancelling_a_filtered_target_returns_to_the_filtered_history(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $deployment = $project->deployments()->create([
            'user_id' => $user->id,
            'ref' => 'main',
            'commit' => str_repeat('a', 40),
            'scheduled_at' => '2026-03-01 00:00:00',
        ]);
        $target = $deployment->targets()->create([
            'server_id' => $server->id,
            'path' => 'C:\\app',
            'preserved_paths' => [],
        ]);

        $this->actingAs($user)->from('/programaciones?status=pending')->post("/targets/{$target->id}/cancel")
            ->assertRedirect('/programaciones?status=pending')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deployment_targets', ['id' => $target->id, 'status' => 'cancelled']);
        $this->get('/programaciones?status=pending')->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 0));
        $this->get('/programaciones?status=cancelled')->assertInertia(fn (Assert $page) => $page->where('deploymentHistory.total', 1));
    }

    public function test_deployment_history_paginates_beyond_the_last_hundred_and_keeps_filters(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $oldest = null;
        for ($i = 0; $i < 105; $i++) {
            $deployment = $project->deployments()->create([
                'user_id' => $user->id,
                'ref' => 'main',
                'commit' => str_repeat('a', 40),
                'scheduled_at' => '2026-03-01 00:00:00',
            ]);
            $oldest ??= $deployment;
        }

        $this->actingAs($user)->get("/programaciones?project_id={$project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deploymentHistory.total', 105)
                ->where('deploymentHistory.last_page', 6)
                ->has('deploymentHistory.data', 20)
                ->where('deploymentHistory.next_page_url', fn (string $url): bool => str_contains($url, "project_id={$project->id}") && str_contains($url, 'page=2')));

        $this->get("/programaciones?project_id={$project->id}&page=6")->assertInertia(fn (Assert $page) => $page
            ->has('deployments', 100)
            ->where('deploymentHistory.current_page', 6)
            ->has('deploymentHistory.data', 5)
            ->where('deploymentHistory.data.4.id', $oldest->id));
    }

    public function test_deployment_history_rejects_invalid_filters(): void
    {
        $this->actingAs(User::factory()->create())->from('/programaciones')
            ->get('/programaciones?'.http_build_query([
                'project_id' => 0,
                'server_id' => ['invalid'],
                'status' => 'invalid',
                'date_from' => '2026-03-05',
                'date_to' => '2026-03-04',
            ]))->assertRedirect('/programaciones')
            ->assertSessionHasErrors([
                'project_id' => 'El campo proyecto debe ser al menos 1.',
                'server_id' => 'El campo servidor debe ser un número entero.',
                'status' => 'El valor seleccionado en estado no es válido.',
                'date_to' => 'La fecha hasta debe ser igual o posterior al 1753-01-01 y a la fecha desde.',
            ]);

        $this->from('/programaciones')->get('/programaciones?date_from=1752-12-31&date_to=9999-12-31')
            ->assertSessionHasErrors([
                'date_from' => 'La fecha desde debe ser igual o posterior al 1753-01-01.',
                'date_to' => 'La fecha hasta debe ser igual o anterior al 9999-12-30.',
            ]);
    }

    public function test_panel_exposes_only_the_authenticated_users_profile_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Usuario de prueba',
            'username' => 'usuario.prueba',
            'email' => 'usuario@example.test',
        ]);

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->where('profileUpdateUrl', 'http://localhost/perfil')
            ->where('passwordUpdateUrl', 'http://localhost/perfil/contrasena')
            ->has('user', fn (Assert $userPage) => $userPage
                ->where('name', 'Usuario de prueba')
                ->where('username', 'usuario.prueba')
                ->where('email', 'usuario@example.test')));
    }

    public function test_authenticated_user_can_update_only_their_own_profile(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($user)->from('/proyectos')->patch(route('profile.update'), [
            'id' => $other->id,
            'name' => 'Perfil actualizado',
            'username' => 'Nuevo.Usuario',
            'email' => 'nuevo@example.test',
            'is_admin' => true,
            'is_active' => false,
            'password' => 'ignored-test-password',
        ])->assertRedirect('/proyectos')
            ->assertSessionHas('success', 'Perfil actualizado.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Perfil actualizado',
            'username' => 'nuevo.usuario',
            'email' => 'nuevo@example.test',
            'is_admin' => false,
            'is_active' => true,
            'password' => $user->getRawOriginal('password'),
        ]);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'email' => $other->email]);
    }

    public function test_profile_rejects_spaces_in_username_and_duplicate_email(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'username' => 'con espacios',
            'email' => $other->email,
        ])->assertInvalid(['username', 'email']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patch(route('profile.update'))->assertRedirectToRoute('login');
    }

    public function test_user_can_change_their_password_and_only_the_current_session_stays_active(): void
    {
        $user = User::factory()->create(['username' => 'usuario']);
        $other = User::factory()->create();
        $newPassword = 'a-new-test-password';
        $previousVersion = $user->session_version;
        $sessionKey = EnsureActiveUser::SESSION_VERSION_KEY;

        $this->actingAs($user)->withSession([$sessionKey => $previousVersion])
            ->from('/proyectos')->patch(route('profile.password.update'), [
                'id' => $other->id,
                'is_admin' => true,
                'current_password' => 'password',
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])->assertRedirect('/proyectos')
            ->assertSessionHas('success', 'Contraseña actualizada.')
            ->assertSessionHas($sessionKey, $previousVersion + 1);

        $changed = $user->fresh();
        $this->assertTrue(Hash::check($newPassword, $changed->password));
        $this->assertFalse($changed->is_admin);
        $this->assertNull($changed->remember_token);
        $this->assertSame($other->password, $other->fresh()->password);
        $this->actingAs($changed)->get('/')->assertOk();
        $this->withSession([$sessionKey => $previousVersion])->get('/')->assertRedirectToRoute('login');
        $this->assertGuest();

        $this->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertInvalid(['username']);
        $this->post('/login', ['username' => $user->username, 'password' => $newPassword])
            ->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_password_change_rejects_invalid_credentials_and_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->patch(route('profile.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'a-new-test-password',
            'password_confirmation' => 'a-new-test-password',
        ])->assertInvalid(['current_password' => 'La contraseña actual no es correcta.'])
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->patch(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-test-password',
            'password_confirmation' => 'a-different-password',
        ])->assertInvalid(['password' => 'La confirmación no coincide con la nueva contraseña.']);

        $this->patch(route('profile.password.update'), [
            'current_password' => 'password',
            'password' => 'eleven-char',
            'password_confirmation' => 'eleven-char',
        ])->assertInvalid(['password']);

        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->patch(route('profile.password.update'))->assertRedirectToRoute('login');
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
            ->where('agentDownloads.installer', 'http://localhost/servidores/descargas/windows/installer')
            ->where('agentDownloads.agent', 'http://localhost/servidores/descargas/windows/agent'));

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
            ->where('agentDownloads.agent', 'http://localhost/servidores/descargas/windows/agent'));
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

        $this->get('/descargas')->assertInertia(fn (Assert $page) => $page->where('agentDownloads.powershell', 'http://localhost'.$url));
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

    public function test_user_can_log_in_with_username(): void
    {
        $user = User::factory()->create(['username' => 'deploy.operator']);

        $this->post('/login', ['username' => 'Deploy.Operator', 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_cannot_be_used_as_login_username(): void
    {
        $user = User::factory()->create([
            'username' => 'deploy.operator',
            'email' => 'operator@example.test',
        ]);

        $this->post('/login', ['username' => $user->email, 'password' => 'password'])
            ->assertInvalid(['username']);
    }

    public function test_create_user_command_sets_a_username(): void
    {
        $this->artisan('panel:create-user')
            ->expectsQuestion('Nombre', 'Operador')
            ->expectsQuestion('Nombre de usuario', 'Deploy.Operator')
            ->expectsQuestion('Correo', 'operator@example.test')
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'a-long-test-password')
            ->expectsQuestion('Confirme la contraseña', 'a-long-test-password')
            ->expectsOutput('Usuario creado como administrador.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'name' => 'Operador',
            'username' => 'deploy.operator',
            'email' => 'operator@example.test',
            'is_admin' => true,
        ]);
    }

    public function test_admin_can_view_the_user_management_section(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'admin']);
        User::factory()->create(['name' => 'Operador', 'username' => 'operator']);

        $this->actingAs($admin)->get('/usuarios')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->where('section', 'users')
            ->where('canManageUsers', true)
            ->has('users', 2)
            ->where('users.0.username', 'admin')
            ->where('users.0.is_admin', true)
            ->missing('users.0.password'));
    }

    public function test_non_admin_cannot_view_the_user_management_section(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/usuarios')
            ->assertForbidden();
    }

    public function test_non_admin_cannot_create_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/usuarios', [
                'name' => 'Usuario no autorizado',
                'username' => 'unauthorized',
                'email' => 'unauthorized@example.test',
                'password' => 'a-long-test-password',
                'password_confirmation' => 'a-long-test-password',
                'is_admin' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.test']);
    }

    public function test_admin_can_create_a_regular_user_from_the_panel(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/usuarios', [
                'name' => 'Nuevo operador',
                'username' => 'New.Operator',
                'email' => 'new.operator@example.test',
                'password' => 'a-long-test-password',
                'password_confirmation' => 'a-long-test-password',
                'is_admin' => false,
            ])
            ->assertSessionHas('success', 'Usuario creado.');

        $this->assertDatabaseHas('users', [
            'name' => 'Nuevo operador',
            'username' => 'new.operator',
            'email' => 'new.operator@example.test',
            'is_admin' => false,
        ]);
    }

    public function test_admin_can_create_another_administrator_from_the_panel(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/usuarios', [
                'name' => 'Nuevo administrador',
                'username' => 'new.admin',
                'email' => 'new.admin@example.test',
                'password' => 'a-long-test-password',
                'password_confirmation' => 'a-long-test-password',
                'is_admin' => true,
            ])
            ->assertSessionHas('success', 'Usuario creado.');

        $this->assertDatabaseHas('users', ['username' => 'new.admin', 'is_admin' => true]);
    }

    public function test_user_creation_rejects_an_invalid_admin_flag(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/usuarios', [
                'name' => 'Cuenta de prueba',
                'username' => 'invalid.role',
                'email' => 'invalid.role@example.test',
                'password' => 'a-long-test-password',
                'password_confirmation' => 'a-long-test-password',
                'is_admin' => 'invalid',
            ])
            ->assertInvalid(['is_admin' => 'Indique si la cuenta debe ser de administrador.']);

        $this->assertDatabaseMissing('users', ['username' => 'invalid.role']);
    }

    public function test_admin_can_edit_an_account_without_changing_its_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'operator@example.test']);
        $originalPassword = $user->password;

        $this->actingAs($admin)->patch(route('users.update', $user), [
            'name' => 'Operador actualizado',
            'username' => 'Updated.Operator',
            'email' => $user->email,
            'is_admin' => true,
        ])->assertRedirectToRoute('panel.users');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Operador actualizado',
            'username' => 'updated.operator',
            'email' => 'operator@example.test',
            'is_admin' => true,
            'password' => $originalPassword,
        ]);
    }

    public function test_user_edit_rejects_another_accounts_username_and_email(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'panel.admin']);
        $user = User::factory()->create();

        $this->actingAs($admin)->patch(route('users.update', $user), [
            'name' => $user->name,
            'username' => 'PANEL.ADMIN',
            'email' => $admin->email,
            'is_admin' => false,
        ])->assertInvalid(['username', 'email']);
    }

    public function test_non_admin_cannot_modify_user_details_or_access(): void
    {
        $operator = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($operator)
            ->patch(route('users.update', $operator), ['is_admin' => true])
            ->assertForbidden();
        $this->patch(route('users.active', $admin), ['is_active' => false])
            ->assertForbidden();
    }

    public function test_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create(['is_active' => false]);

        $this->actingAs($admin)->patch(route('users.update', $admin), [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'is_admin' => false,
        ])->assertInvalid(['is_admin' => 'Debe quedar al menos un administrador activo.']);
        $this->patch(route('users.active', $admin), ['is_active' => false])
            ->assertInvalid(['is_active' => 'Debe quedar al menos un administrador activo.']);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_admin' => true, 'is_active' => true]);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertInvalid(['username' => 'Credenciales incorrectas.']);

        $this->assertGuest();
    }

    public function test_reactivation_allows_fresh_login_but_does_not_restore_old_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch(route('users.active', $user), ['is_active' => false])
            ->assertSessionHas('success', 'Usuario desactivado.');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->patch(route('users.active', $user), ['is_active' => true])
            ->assertSessionHas('success', 'Usuario reactivado.');

        $this->actingAs($user->fresh())->get('/')
            ->assertRedirectToRoute('login');
        $this->assertGuest();

        $this->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertRedirect('/');
        $this->get('/')->assertOk();
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

    public function test_project_creation_rejects_duplicate_name_and_repository_including_trashed_projects(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $project = $this->makeProject();
        $user = User::factory()->create();
        $data = ['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test/', 'owner' => 'team', 'repository' => 'app'];

        $this->actingAs($user)->post('/projects', $data)->assertSessionHasErrors([
            'name' => 'Ya existe un proyecto con este nombre.',
            'repository' => 'Este repositorio ya está registrado para esta URL de Gitea y propietario.',
        ]);
        $this->post('/projects', [...$data, 'repository' => 'other'])->assertSessionHasErrors('name');
        $this->post('/projects', [...$data, 'name' => 'Otro proyecto'])->assertSessionHasErrors('repository');

        $project->delete();
        $this->post('/projects', $data)->assertSessionHasErrors([
            'name' => 'Ya existe un proyecto con este nombre en la papelera. Restáurelo en lugar de crear otro.',
            'repository' => 'Este repositorio ya pertenece a un proyecto en la papelera. Restáurelo en lugar de crear otro.',
        ]);

        $this->assertDatabaseCount('projects', 1);
        Http::assertNothingSent();
    }

    public function test_project_edit_allows_unchanged_identity_but_rejects_conflicts(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $project = $this->makeProject();
        $other = $this->makeProject(['name' => 'Otro proyecto', 'repository' => 'other']);
        $data = ['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app'];
        $this->actingAs(User::factory()->create());

        $this->patch("/projects/{$project->id}", $data)->assertSessionHasNoErrors();
        $this->patch("/projects/{$project->id}", [...$data, 'name' => $other->name])->assertSessionHasErrors('name');
        $this->patch("/projects/{$project->id}", [...$data, 'repository' => $other->repository])->assertSessionHasErrors('repository');

        $other->delete();
        $this->patch("/projects/{$project->id}", [...$data, 'name' => $other->name])->assertSessionHasErrors([
            'name' => 'Ya existe un proyecto con este nombre en la papelera. Restáurelo en lugar de crear otro.',
        ]);
        $this->patch("/projects/{$project->id}", [...$data, 'repository' => $other->repository])->assertSessionHasErrors([
            'repository' => 'Este repositorio ya pertenece a un proyecto en la papelera. Restáurelo en lugar de crear otro.',
        ]);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Proyecto', 'repository' => 'app']);
        Http::assertNothingSent();
    }

    public function test_project_identity_is_enforced_by_the_database_including_trashed_projects(): void
    {
        $project = $this->makeProject();
        $project->delete();

        $this->assertThrows(
            fn () => $this->makeProject(['repository' => 'different']),
            UniqueConstraintViolationException::class,
        );
        $this->assertThrows(
            fn () => $this->makeProject(['name' => 'Otro proyecto']),
            UniqueConstraintViolationException::class,
        );
    }

    public function test_project_can_use_the_same_repository_name_on_another_gitea_server(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://other.test/api/v1/repos/team/app/tags*' => Http::response([])]);
        $this->makeProject();

        $this->actingAs(User::factory()->create())->post('/projects', [
            'name' => 'Otro proyecto',
            'gitea_url' => 'https://other.test/',
            'owner' => 'team',
            'repository' => 'app',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projects', ['name' => 'Otro proyecto', 'gitea_url' => 'https://other.test', 'repository' => 'app']);
        Http::assertSentCount(1);
    }

    public function test_project_can_be_edited_without_exposing_or_clearing_its_saved_token(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $project = Project::create([
            'name' => 'Proyecto',
            'gitea_url' => 'https://gitea.test',
            'owner' => 'team',
            'repository' => 'app',
            'gitea_token' => 'saved-secret',
            'preserved_paths' => ['uploads'],
        ]);
        $data = [
            'name' => 'Proyecto editado',
            'gitea_url' => 'https://gitea.test',
            'owner' => 'team',
            'repository' => 'app',
            'ssh_url' => '',
            'gitea_token' => '',
            'preserved_paths' => "uploads\nstorage",
        ];

        $this->actingAs($user)->patch("/projects/{$project->id}", [...$data, 'gitea_url' => 'http://gitea.test'])
            ->assertSessionHasErrors(['gitea_token' => 'Un token de Gitea requiere HTTPS.']);
        $this->assertSame('https://gitea.test', $project->fresh()->gitea_url);
        $this->assertSame('saved-secret', $project->fresh()->gitea_token);
        Http::assertNothingSent();

        $this->actingAs($user)->patch("/projects/{$project->id}", $data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Proyecto actualizado.');
        Http::assertNothingSent();
        $this->assertSame('saved-secret', $project->fresh()->gitea_token);
        $this->assertSame(['uploads', 'storage'], $project->fresh()->preserved_paths);

        $this->patch("/projects/{$project->id}", [...$data, 'gitea_token' => 'replacement-secret'])->assertSessionHasNoErrors();

        $this->assertSame('replacement-secret', $project->fresh()->gitea_token);
        Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'token replacement-secret'));
        $this->get('/proyectos')->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.name', 'Proyecto editado')
            ->where('projects.0.gitea_url', 'https://gitea.test')
            ->where('projects.0.has_gitea_token', true)
            ->missing('projects.0.gitea_token'));
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

    public function test_bulk_tag_sync_continues_after_a_repository_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/broken/tags*' => Http::response([], 503),
            'https://gitea.test/api/v1/repos/team/app/tags*' => Http::response([
                ['name' => 'v3.0.0', 'commit' => ['sha' => str_repeat('f', 40)]],
            ]),
        ]);
        $broken = $this->makeProject(['name' => 'Proyecto roto', 'repository' => 'broken']);
        $broken->forceFill(['latest_tag' => 'v1.0.0'])->save();
        $project = $this->makeProject();
        $project->update(['is_active' => false]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.tags.sync-all'))
            ->assertRedirectToRoute('panel.projects')
            ->assertSessionHas('error', 'Se sincronizaron 1 de 2 proyectos. No se pudieron consultar 1. Revise las tarjetas de los proyectos.');

        $this->assertSame('v1.0.0', $broken->fresh()->latest_tag);
        $this->assertNotNull($broken->fresh()->latest_tag_sync_error);
        $this->assertSame('v3.0.0', $project->fresh()->latest_tag);
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_bulk_tag_sync_reports_success_when_all_repositories_respond(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://gitea.test/api/v1/repos/team/*/tags*' => Http::response([])]);
        $this->makeProject();
        $second = $this->makeProject(['name' => 'Otro proyecto', 'repository' => 'other']);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.tags.sync-all'))
            ->assertRedirectToRoute('panel.projects')
            ->assertSessionHas('success', 'Se sincronizaron 2 de 2 proyectos.');

        Http::assertSentCount(2);
    }

    public function test_bulk_tag_sync_requires_authentication_and_handles_an_empty_catalog(): void
    {
        Http::fake();

        $this->post(route('projects.tags.sync-all'))->assertRedirectToRoute('login');

        $this->actingAs(User::factory()->create())
            ->post(route('projects.tags.sync-all'))
            ->assertRedirectToRoute('panel.projects')
            ->assertSessionHas('success', 'No hay proyectos para sincronizar.');

        Http::assertNothingSent();
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

    public function test_deployment_references_list_branches_and_tags_without_commits(): void
    {
        $project = $this->makeProject();
        $sha = str_repeat('a', 40);
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/branches?*' => Http::response([
                ['name' => 'release/stable', 'commit' => ['id' => $sha]],
            ]),
            'https://gitea.test/api/v1/repos/team/app/tags?*' => Http::response([
                ['name' => 'stable', 'commit' => ['sha' => $sha]],
                ['name' => 'v2.0.0', 'commit' => ['sha' => $sha]],
            ]),
        ]);
        $url = route('projects.references', $project);

        $this->get($url)->assertRedirectToRoute('login');
        Http::assertNothingSent();
        $this->actingAs(User::factory()->create())->getJson($url)
            ->assertOk()->assertExactJson(['branches' => ['release/stable'], 'tags' => ['v2.0.0', 'stable']]);
    }

    public function test_deployment_selector_limits_tags_after_sorting_all_pages_without_limiting_branches(): void
    {
        $project = $this->makeProject();
        $sha = str_repeat('a', 40);
        $tags = fn (array $versions): array => array_map(
            fn (int $version): array => ['name' => "v1.0.{$version}", 'commit' => ['sha' => $sha]],
            $versions,
        );
        $branches = array_map(fn (int $number): string => "release/{$number}", range(22, 1));
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/branches?*' => Http::response(array_map(
                fn (string $branch): array => ['name' => $branch, 'commit' => ['id' => $sha]],
                array_reverse($branches),
            )),
            'https://gitea.test/api/v1/repos/team/app/tags?*' => Http::sequence()
                ->push($tags(range(1, 50)))
                ->push($tags([51])),
        ]);

        $this->actingAs(User::factory()->create())->getJson(route('projects.references', $project))
            ->assertOk()->assertExactJson([
                'branches' => $branches,
                'tags' => array_map(fn (int $version): string => "v1.0.{$version}", range(51, 32)),
            ]);
    }

    public function test_deployment_accepts_a_tag_but_rejects_a_raw_commit_and_missing_tag(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $sha = str_repeat('a', 40);
        Http::preventStrayRequests();
        Http::fake([
            'https://gitea.test/api/v1/repos/team/app/tags?*' => Http::response([
                ['name' => 'release/stable', 'commit' => ['sha' => $sha]],
            ]),
        ]);
        $data = [
            'project_id' => $project->id, 'servers' => [$server->id],
            'scheduled_at' => now('America/Bogota')->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ];

        $this->actingAs($user)->post('/deployments', $data + ['ref' => $sha])->assertInvalid(['ref']);
        Http::assertNothingSent();
        $this->post('/deployments', $data + ['ref' => 'refs/tags/missing'])->assertInvalid(['ref']);
        $this->post('/deployments', $data + ['ref' => 'refs/tags/release/stable'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('deployments', 1);
        $this->assertDatabaseHas('deployments', ['ref' => 'refs/tags/release/stable', 'commit' => $sha]);
    }

    public function test_programming_pins_commit_and_snapshots_each_target(): void
    {
        Http::fake(['*' => Http::response([['name' => 'main', 'commit' => ['id' => str_repeat('a', 40)]]])]);
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => ['uploads']]);
        $server = Server::create(['name' => 'Servidor']);
        $this->actingAs($user)->post('/assignments', ['project_id' => $project->id, 'server_id' => $server->id, 'path' => 'C:\\wamp64\\www\\app'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'refs/heads/main',
            'scheduled_at' => now('America/Bogota')->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ])->assertSessionHasNoErrors();
        $deployment = Deployment::firstOrFail();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_deployment_id' => $deployment->id,
        ]);
        $this->assertDatabaseHas('deployments', ['commit' => str_repeat('a', 40), 'ref' => 'refs/heads/main']);
        $this->assertDatabaseHas('deployment_targets', ['server_id' => $server->id, 'status' => 'pending', 'path' => 'C:\\wamp64\\www\\app']);
        $this->assertSame(['uploads'], DeploymentTarget::firstOrFail()->preserved_paths);
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'refs/heads/main',
            'scheduled_at' => now('America/Bogota')->addDays(2)->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ])->assertSessionHasErrors('servers');
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_deployment_uses_the_configured_timezone_and_stores_utc(): void
    {
        config(['app.deployment_timezone' => 'America/New_York']);
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(12, 0));
        Http::preventStrayRequests();
        Http::fake(['https://gitea.test/api/v1/repos/team/app/branches?*' => Http::response([
            ['name' => 'main', 'commit' => ['id' => str_repeat('a', 40)]],
        ])]);
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);

        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id,
            'servers' => [$server->id],
            'ref' => 'refs/heads/main',
            'scheduled_at' => '2026-11-24T09:00',
            'timezone' => 'America/New_York',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deployments', ['scheduled_at' => '2026-11-24 14:00:00']);
        Http::assertSentCount(1);
    }

    public function test_deployment_rejects_an_outdated_timezone(): void
    {
        config(['app.deployment_timezone' => 'America/New_York']);
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);

        $this->actingAs(User::factory()->create())->post('/deployments', [
            'project_id' => $project->id,
            'servers' => [$server->id],
            'ref' => 'refs/heads/main',
            'scheduled_at' => '2026-11-24T09:00',
            'timezone' => 'America/Bogota',
        ])->assertSessionHasErrors([
            'timezone' => 'La zona horaria de programación cambió. Recargue la página antes de continuar.',
        ]);

        $this->assertDatabaseCount('deployments', 0);
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
                ->where('latest_deployment.ref', 'refs/heads/main')
                ->where('latest_deployment.commit', str_repeat('b', 40))
                ->where('latest_deployment.targets.0.server_name', 'Servidor')
                ->where('latest_deployment.targets.0.status', 'succeeded')
                ->etc()));
    }

    public function test_projects_show_each_servers_last_success_for_its_assigned_path(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $otherProject = $this->makeProject(['name' => 'Otro proyecto', 'repository' => 'other']);
        $first = Server::create(['name' => 'Primero']);
        $second = Server::create(['name' => 'Segundo']);
        $third = Server::create(['name' => 'Tercero']);
        $project->servers()->attach([$first->id, $second->id, $third->id], ['path' => 'C:\\app']);

        $record = function (Project $owner, Server $server, string $ref, string $status, string $finishedAt, string $path = 'C:\\app') use ($user): void {
            $deployment = $owner->deployments()->create([
                'user_id' => $user->id,
                'ref' => $ref,
                'commit' => str_repeat('b', 40),
                'scheduled_at' => $finishedAt,
                'repository_url' => $owner->gitea_url,
            ]);
            $deployment->targets()->create([
                'server_id' => $server->id,
                'path' => $path,
                'preserved_paths' => [],
            ])->forceFill(['status' => $status, 'finished_at' => $finishedAt])->save();
        };

        $record($project, $first, 'refs/tags/v2.0.0', 'succeeded', '2026-11-20 12:00:00');
        $record($project, $first, 'v1.0.0', 'succeeded', '2026-11-20 11:00:00');
        $record($project, $first, 'v3.0.0', 'uncertain', '2026-11-20 13:00:00');
        $record($project, $second, 'main', 'succeeded', '2026-11-20 12:30:00');
        $record($project, $second, 'v4.0.0', 'failed', '2026-11-20 14:00:00');
        $record($project, $third, 'v3.0.0', 'succeeded', '2026-11-20 13:00:00', 'C:\\old');
        $record($otherProject, $third, 'v5.0.0', 'succeeded', '2026-11-20 15:00:00');

        $this->actingAs($user)->get('/proyectos')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->where('projects', function (Collection $projects) use ($project, $first, $second, $third): bool {
                $servers = collect($projects->firstWhere('id', $project->id)['servers'])->keyBy('id');

                $this->assertSame([
                    'ref' => 'refs/tags/v2.0.0',
                    'commit' => str_repeat('b', 40),
                    'finished_at' => '2026-11-20 12:00',
                ], $servers[$first->id]['last_successful_deployment']);
                $this->assertTrue($servers[$first->id]['deployment_uncertain']);
                $this->assertSame('main', $servers[$second->id]['last_successful_deployment']['ref']);
                $this->assertFalse($servers[$second->id]['deployment_uncertain']);
                $this->assertNull($servers[$third->id]['last_successful_deployment']);

                return true;
            }));
    }

    public function test_projects_recommend_newer_tags_only_when_the_server_commit_is_confirmed_behind(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $project->forceFill([
            'latest_tag' => 'v3.0.0',
            'latest_tag_commit' => str_repeat('a', 40),
            'latest_tag_synced_at' => now(),
        ])->save();

        $servers = collect([
            'Atrasado',
            'Actualizado',
            'Rama atrasada',
            'Rama no comparable',
            'Versión posterior',
            'Error de comparación',
            'Incierto',
            'Sin historial',
        ])->mapWithKeys(fn (string $name): array => [$name => Server::create(['name' => $name])]);
        $project->servers()->attach($servers->pluck('id')->all(), ['path' => 'C:\\app']);

        $record = function (Server $server, string $ref, string $commit, string $status, string $finishedAt) use ($project, $user): void {
            $deployment = $project->deployments()->create([
                'user_id' => $user->id,
                'ref' => $ref,
                'commit' => $commit,
                'scheduled_at' => $finishedAt,
                'repository_url' => $project->gitea_url,
            ]);
            $deployment->targets()->create([
                'server_id' => $server->id,
                'path' => 'C:\\app',
                'preserved_paths' => [],
            ])->forceFill(['status' => $status, 'finished_at' => $finishedAt])->save();
        };

        $record($servers['Atrasado'], 'refs/tags/v2.9.9', str_repeat('b', 40), 'succeeded', '2026-11-20 10:00:00');
        $record($servers['Actualizado'], 'refs/heads/main', str_repeat('a', 40), 'succeeded', '2026-11-20 11:00:00');
        $record($servers['Rama no comparable'], 'refs/tags/v2.8.0', str_repeat('c', 40), 'succeeded', '2026-11-20 12:00:00');
        $record($servers['Rama atrasada'], 'refs/heads/main', str_repeat('7', 40), 'succeeded', '2026-11-20 13:00:00');
        $record($servers['Versión posterior'], 'refs/tags/v3.1.0', str_repeat('d', 40), 'succeeded', '2026-11-20 14:00:00');
        $record($servers['Error de comparación'], 'refs/heads/main', str_repeat('6', 40), 'succeeded', '2026-11-20 15:00:00');
        $record($servers['Incierto'], 'refs/tags/v2.0.0', str_repeat('e', 40), 'succeeded', '2026-11-20 16:00:00');
        $record($servers['Incierto'], 'refs/tags/v2.0.0', str_repeat('f', 40), 'uncertain', '2026-11-20 17:00:00');

        $failedSyncProject = $this->makeProject(['name' => 'Proyecto roto', 'repository' => 'broken']);
        $failedSyncProject->forceFill([
            'latest_tag' => 'v3.0.0',
            'latest_tag_commit' => str_repeat('a', 40),
            'latest_tag_synced_at' => now(),
            'latest_tag_sync_error' => 'No fue posible consultar los tags.',
        ])->save();
        $failedSyncServer = Server::create(['name' => 'Sincronización fallida']);
        $failedSyncProject->servers()->attach($failedSyncServer, ['path' => 'C:\\app']);
        $recordForFailedSync = $failedSyncProject->deployments()->create([
            'user_id' => $user->id,
            'ref' => 'refs/tags/v2.0.0',
            'commit' => str_repeat('b', 40),
            'scheduled_at' => '2026-11-20 16:00:00',
            'repository_url' => $failedSyncProject->gitea_url,
        ]);
        $recordForFailedSync->targets()->create([
            'server_id' => $failedSyncServer->id,
            'path' => 'C:\\app',
            'preserved_paths' => [],
        ])->forceFill(['status' => 'succeeded', 'finished_at' => '2026-11-20 17:00:00'])->save();

        Http::preventStrayRequests();
        Http::fake(function (ClientRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (is_string($path) && str_contains($path, '/compare/'.str_repeat('6', 40).'...'.str_repeat('a', 40))) {
                return Http::response(['message' => 'Repositorio no disponible.'], 503);
            }

            $matchingParentCommit = match (true) {
                is_string($path) && str_contains($path, '/compare/'.str_repeat('b', 40).'...'.str_repeat('a', 40)) => str_repeat('b', 40),
                is_string($path) && str_contains($path, '/compare/'.str_repeat('7', 40).'...'.str_repeat('a', 40)) => str_repeat('7', 40),
                is_string($path) && str_contains($path, '/compare/'.str_repeat('a', 40).'...'.str_repeat('d', 40)) => str_repeat('a', 40),
                default => str_repeat('8', 40),
            };

            return Http::response([
                'commits' => [['parents' => [['sha' => $matchingParentCommit]]]],
            ]);
        });
        $this->actingAs($user)->get('/proyectos')->assertInertia(fn (Assert $page) => $page
            ->component('Panel')
            ->where('projects', function (Collection $projects) use ($project, $failedSyncProject, $servers, $failedSyncServer): bool {
                $listedProjects = $projects->keyBy('id');
                $listedServers = collect($listedProjects[$project->id]['servers'])->keyBy('id');

                $this->assertSame('behind', $listedServers[$servers['Atrasado']->id]['version_status']);
                $this->assertSame('current', $listedServers[$servers['Actualizado']->id]['version_status']);
                $this->assertSame('unknown', $listedServers[$servers['Rama no comparable']->id]['version_status']);
                $this->assertSame('Los commits no pertenecen a la misma línea de desarrollo; puede haber divergencia.', $listedServers[$servers['Rama no comparable']->id]['version_status_reason']);
                $this->assertSame('behind', $listedServers[$servers['Rama atrasada']->id]['version_status']);
                $this->assertSame('ahead', $listedServers[$servers['Versión posterior']->id]['version_status']);
                $this->assertSame('unknown', $listedServers[$servers['Error de comparación']->id]['version_status']);
                $this->assertSame('No se pudo verificar la relación de commits con el repositorio.', $listedServers[$servers['Error de comparación']->id]['version_status_reason']);
                $this->assertSame('unknown', $listedServers[$servers['Incierto']->id]['version_status']);
                $this->assertSame('unknown', $listedServers[$servers['Sin historial']->id]['version_status']);
                $this->assertSame('unknown', collect($listedProjects[$failedSyncProject->id]['servers'])
                    ->firstWhere('id', $failedSyncServer->id)['version_status']);

                return true;
            }));
        Http::assertSentCount(7);
    }

    public function test_unknown_ref_or_unassigned_server_does_not_create_deployment(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $project = Project::create(['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => []]);
        $server = Server::create(['name' => 'Servidor']);
        $data = ['project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'refs/heads/missing', 'scheduled_at' => now('America/Bogota')->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota'];
        $this->actingAs(User::factory()->create())->post('/deployments', $data)->assertSessionHasErrors('servers');
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $this->post('/deployments', $data)->assertSessionHasErrors('ref');
        $this->assertDatabaseCount('deployments', 0);
    }

    private function scheduledDeployment(User $user, Project $project, Server $server): void
    {
        Http::fake(['*' => Http::response([['name' => 'main', 'commit' => ['id' => str_repeat('b', 40)]]])]);
        $project->servers()->syncWithoutDetaching([$server->id => ['path' => 'C:\\app']]);
        $this->actingAs($user)->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'refs/heads/main',
            'scheduled_at' => now('America/Bogota')->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota',
        ])->assertSessionHasNoErrors();
    }

    private function makeProject(array $attributes = []): Project
    {
        return Project::create([...['name' => 'Proyecto', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => []], ...$attributes]);
    }

    public function test_inactive_server_or_project_cannot_be_scheduled(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $data = ['project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'refs/heads/main', 'scheduled_at' => now('America/Bogota')->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'America/Bogota'];

        $this->actingAs($user)->post("/servers/{$server->id}/active", ['is_active' => false])->assertSessionHasNoErrors();
        $this->post('/deployments', $data)->assertSessionHasErrors('servers');

        $server->update(['is_active' => true]);
        $this->post("/projects/{$project->id}/active", ['is_active' => false]);
        $this->post('/deployments', $data)->assertSessionHasErrors('project_id');
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_server_with_history_cannot_be_deleted_and_project_history_can_be_trashed_and_restored_after_active_work_finishes(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject();
        $server = Server::create(['name' => 'Servidor']);
        $this->scheduledDeployment($user, $project, $server);

        $this->delete("/servers/{$server->id}")->assertSessionHasErrors('server');
        $this->delete("/projects/{$project->id}")->assertSessionHasErrors([
            'project' => 'El proyecto tiene despliegues pendientes o en ejecución; cancélelos antes de enviarlo a la papelera.',
        ]);
        $this->assertNotSoftDeleted($project);
        $this->assertDatabaseHas('servers', ['id' => $server->id]);

        $this->post('/deployments/'.Deployment::firstOrFail()->id.'/cancel')->assertSessionHasNoErrors();
        $target = DeploymentTarget::firstOrFail();
        $target->status = 'running';
        $target->save();
        $server->update(['active_target_id' => $target->id]);
        $this->delete("/projects/{$project->id}")->assertSessionHasErrors([
            'project' => 'El proyecto tiene despliegues pendientes o en ejecución; cancélelos antes de enviarlo a la papelera.',
        ]);
        $this->assertNotSoftDeleted($project);
        $this->assertDatabaseHas('deployment_targets', ['status' => 'running']);

        $target->status = 'cancelled';
        $target->save();
        $server->update(['active_target_id' => null]);
        $this->delete("/projects/{$project->id}")->assertSessionHasNoErrors();

        $this->assertSoftDeleted($project);
        $this->assertDatabaseHas('deployments', ['project_id' => $project->id]);
        $this->get('/proyectos')->assertInertia(fn (Assert $page) => $page
            ->has('projects', 0)
            ->where('trashedProjects.0.id', $project->id));
        $this->get('/programaciones')->assertInertia(fn (Assert $page) => $page
            ->where('deployments.0.project_name', $project->name));

        $this->patch("/projects/{$project->id}/restore")->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted(Project::withTrashed()->findOrFail($project->id));
        $this->assertDatabaseHas('project_server', [
            'project_id' => $project->id,
            'server_id' => $server->id,
            'path' => 'C:\\app',
        ]);
        $this->get('/proyectos')->assertInertia(fn (Assert $page) => $page
            ->where('projects.0.id', $project->id)
            ->has('trashedProjects', 0));

        $unused = Server::create(['name' => 'Libre']);
        $unusedProject = Project::create(['name' => 'Libre', 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => 'x', 'preserved_paths' => []]);
        $unusedProject->servers()->attach($unused, ['path' => 'C:\\x']);
        $this->delete("/projects/{$unusedProject->id}")->assertSessionHasNoErrors();
        $this->delete("/servers/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('servers', ['id' => $unused->id]);
        $this->assertSoftDeleted($unusedProject);
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
