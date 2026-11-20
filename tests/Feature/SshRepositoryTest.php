<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\SshRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SshRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('El transporte SSH simulado requiere un shell POSIX.');
        }
        $this->fixture = storage_path('framework/testing/ssh-'.Str::uuid());
        File::makeDirectory($this->fixture, 0700, true);
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.name', 'Test']);
        $this->git(['config', 'user.email', 'test@example.invalid']);
        $this->git(['config', 'commit.gpgsign', 'false']);
        file_put_contents($this->fixture.'/app.txt', 'test');
        $this->git(['add', 'app.txt']);
        $this->git(['commit', '-qm', 'initial']);
        $this->git(['tag', '-a', 'v1', '-m', 'release']);
        $this->git(['tag', 'v1.9.9']);
        $this->git(['tag', '-a', 'v1.10.0', '-m', 'release']);
        $this->originalPath = getenv('PATH');
        $path = base_path('tests/Fixtures').PATH_SEPARATOR.$this->originalPath;
        putenv('PATH='.$path);
        putenv('PANEL_TEST_REPOSITORY='.$this->fixture);
        $_SERVER['PATH'] = $_ENV['PATH'] = $path;
        $_SERVER['PANEL_TEST_REPOSITORY'] = $_ENV['PANEL_TEST_REPOSITORY'] = $this->fixture;
    }

    protected function tearDown(): void
    {
        if (isset($this->originalPath)) {
            putenv('PATH='.$this->originalPath);
            putenv('PANEL_TEST_REPOSITORY');
            $_SERVER['PATH'] = $_ENV['PATH'] = $this->originalPath;
            unset($_SERVER['PANEL_TEST_REPOSITORY'], $_ENV['PANEL_TEST_REPOSITORY']);
        }
        if (isset($this->fixture)) {
            File::deleteDirectory($this->fixture);
        }
        parent::tearDown();
    }

    private function git(array $arguments): string
    {
        $process = new Process(array_merge(['git'], $arguments), $this->fixture);
        $process->mustRun();

        return trim($process->getOutput());
    }

    public function test_ssh_urls_reject_mismatched_paths_and_credentials(): void
    {
        $this->assertTrue(SshRepository::validUrl('git@gitea.test:team/app.git', 'team', 'app'));
        $this->assertTrue(SshRepository::validUrl('ssh://git@gitea.test:2222/team/app.git', 'team', 'app'));
        $this->assertFalse(SshRepository::validUrl('ssh://git:password@gitea.test/team/app.git', 'team', 'app'));
        $this->assertFalse(SshRepository::validUrl('git@gitea.test:other/app.git', 'team', 'app'));
        $this->assertFalse(SshRepository::validUrl('ssh://git@gitea.test:99999/team/app.git', 'team', 'app'));
    }

    public function test_resolves_branch_tag_and_sha_using_verified_ssh_options(): void
    {
        $sha = $this->git(['rev-parse', 'HEAD']);
        $service = new SshRepository;
        $url = 'git@gitea.test:team/app.git';
        $this->assertSame($sha, $service->resolve($url, 'main'));
        $this->assertSame($sha, $service->resolve($url, 'v1'));
        $this->assertSame($sha, $service->resolve($url, $sha));
    }

    public function test_ambiguous_names_are_rejected(): void
    {
        $this->git(['branch', 'v1']);
        $this->expectException(RuntimeException::class);
        (new SshRepository)->resolve('git@gitea.test:team/app.git', 'v1');
    }

    public function test_schedule_snapshots_ssh_url_without_calling_http(): void
    {
        Http::preventStrayRequests();
        $project = Project::create(['name' => 'Proyecto', 'gitea_url' => 'http://gitea.test', 'owner' => 'team', 'repository' => 'app', 'ssh_url' => 'git@gitea.test:team/app.git', 'preserved_paths' => []]);
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $this->actingAs(User::factory()->create())->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main',
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'UTC',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deployments', ['repository_url' => 'git@gitea.test:team/app.git', 'commit' => $this->git(['rev-parse', 'HEAD'])]);
        $this->post('/projects/'.$project->id.'/ssh', ['ssh_url' => 'git@other.test:team/app.git'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'latest_tag' => 'v1.10.0',
            'latest_tag_commit' => $this->git(['rev-parse', 'HEAD']),
        ]);
        $server->agent_token_hash = hash('sha256', str_repeat('a', 64));
        $server->save();
        $project->deployments()->firstOrFail()->update(['scheduled_at' => now()->subMinute()]);
        $this->postJson('https://localhost/api/agent/claim', [], ['Authorization' => 'Bearer '.$server->id.'.'.str_repeat('a', 64)])
            ->assertJsonPath('repository_url', 'git@gitea.test:team/app.git');
    }

    public function test_insecure_http_does_not_resolve_a_version_without_ssh(): void
    {
        Http::preventStrayRequests();
        $project = Project::create(['name' => 'Proyecto', 'gitea_url' => 'http://gitea.test', 'owner' => 'team', 'repository' => 'app', 'preserved_paths' => []]);
        $server = Server::create(['name' => 'Servidor']);
        $project->servers()->attach($server, ['path' => 'C:\\app']);
        $this->actingAs(User::factory()->create())->post('/deployments', [
            'project_id' => $project->id, 'servers' => [$server->id], 'ref' => 'main',
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'), 'timezone' => 'UTC',
        ])->assertSessionHasErrors('ref');
        $this->assertDatabaseCount('deployments', 0);
    }
}
