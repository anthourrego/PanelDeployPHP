<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    private function server(string $name): Server
    {
        $server = Server::create(['name' => $name]);
        $server->agent_token_hash = hash('sha256', str_repeat('a', 64));
        $server->save();

        return $server;
    }

    private function target(Server $server, bool $future = false): DeploymentTarget
    {
        $number = Project::count() + 1;
        $project = Project::create(['name' => "Proyecto {$number}", 'gitea_url' => 'https://gitea.test', 'owner' => 'team', 'repository' => "app-{$number}", 'preserved_paths' => ['uploads']]);
        $deployment = Deployment::create([
            'project_id' => $project->id, 'user_id' => User::factory()->create()->id, 'ref' => 'main',
            'commit' => str_repeat('b', 40), 'scheduled_at' => $future ? now()->addDay() : now()->subDay(),
            'repository_url' => "https://gitea.test/team/app-{$number}.git",
        ]);

        $target = $deployment->targets()->create(['server_id' => $server->id, 'path' => 'C:\\app', 'preserved_paths' => ['uploads']]);
        DB::table('deployment_reservations')->insert(['project_id' => $project->id, 'server_id' => $server->id, 'deployment_target_id' => $target->id, 'commit' => $deployment->commit]);

        return $target;
    }

    private function headers(Server $server): array
    {
        return ['Authorization' => 'Bearer '.$server->id.'.'.str_repeat('a', 64)];
    }

    public function test_agent_requires_https_and_individual_token(): void
    {
        $server = $this->server('Uno');
        $this->postJson('http://localhost/api/agent/claim', [], $this->headers($server))->assertStatus(426);
        $this->postJson('https://localhost/api/agent/claim')->assertUnauthorized();
        $this->postJson('https://localhost/api/agent/claim', [], ['Authorization' => 'Bearer '.$server->id.'.'.str_repeat('b', 64)])->assertUnauthorized();
        $this->postJson('https://localhost/api/agent/claim', [], $this->headers($server))->assertOk()->assertJsonPath('state', 'idle');
    }

    public function test_claim_is_server_scoped_due_and_not_redelivered(): void
    {
        $server = $this->server('Uno');
        $other = $this->server('Dos');
        $future = $this->target($server, true);
        $otherTarget = $this->target($other);
        $target = $this->target($server);
        $headers = $this->headers($server);
        $this->postJson('https://localhost/api/agent/claim', [], $headers)
            ->assertOk()->assertJsonPath('state', 'claimed')->assertJsonPath('target_id', $target->id)
            ->assertJsonPath('commit', str_repeat('b', 40))->assertJsonPath('repository_url', 'https://gitea.test/team/app-3.git')
            ->assertJsonPath('preserved_paths.0', 'uploads');
        $this->postJson('https://localhost/api/agent/claim', [], $headers)->assertOk()->assertJsonPath('state', 'busy');
        $this->assertSame('pending', $future->fresh()->status);
        $this->assertSame('pending', $otherTarget->fresh()->status);
    }

    public function test_report_is_owned_idempotent_and_releases_server(): void
    {
        $server = $this->server('Uno');
        $other = $this->server('Dos');
        $target = $this->target($server);
        $this->postJson('https://localhost/api/agent/claim', [], $this->headers($server))->assertOk();
        $url = 'https://localhost/api/agent/targets/'.$target->id.'/report';
        $result = ['status' => 'succeeded', 'result' => 'Commit verificado.'];
        $this->postJson($url, $result, $this->headers($other))->assertNotFound();
        $this->postJson($url, $result, $this->headers($server))->assertOk();
        $this->postJson($url, $result, $this->headers($server))->assertOk();
        $this->postJson($url, ['status' => 'failed', 'result' => 'Otro'], $this->headers($server))->assertStatus(409);
        $this->assertNull($server->fresh()->active_target_id);
        $this->assertSame('succeeded', $target->fresh()->status);
        $this->assertDatabaseMissing('deployment_reservations', ['deployment_target_id' => $target->id]);
    }

    public function test_uncertain_work_remains_locked_until_manual_review(): void
    {
        $server = $this->server('Uno');
        $target = $this->target($server);
        $headers = $this->headers($server);
        $this->postJson('https://localhost/api/agent/claim', [], $headers)->assertOk();
        $result = ['status' => 'uncertain', 'result' => 'Revisar el servidor.'];
        $this->postJson('https://localhost/api/agent/targets/'.$target->id.'/report', $result, $headers)->assertOk();
        $this->postJson('https://localhost/api/agent/claim', [], $headers)->assertJsonPath('state', 'busy');
        $this->assertSame($target->id, $server->fresh()->active_target_id);
        $this->assertDatabaseHas('deployment_reservations', ['deployment_target_id' => $target->id]);
        $this->artisan('panel:resolve-stuck', ['target_id' => $target->id])
            ->expectsConfirmation('¿Confirmó en Windows que el agente está detenido y revisó el estado del repositorio? El trabajo se marcará como fallido.', 'yes')
            ->assertSuccessful();
        $this->assertSame('failed', $target->fresh()->status);
        $this->assertNull($server->fresh()->active_target_id);
        $this->assertDatabaseMissing('deployment_reservations', ['deployment_target_id' => $target->id]);
    }

    public function test_only_pending_work_can_be_cancelled(): void
    {
        $server = $this->server('Uno');
        $pending = $this->target($server);
        $active = $this->target($server);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/targets/'.$pending->id.'/cancel')->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertDatabaseMissing('deployment_reservations', ['deployment_target_id' => $pending->id]);
        $this->postJson('https://localhost/api/agent/claim', [], $this->headers($server))->assertJsonPath('target_id', $active->id);
        $this->actingAs($user)->post('/targets/'.$active->id.'/cancel')->assertSessionHasErrors('target');
        $this->assertSame('running', $active->fresh()->status);
        $this->assertDatabaseHas('deployment_reservations', ['deployment_target_id' => $active->id]);
    }
}
