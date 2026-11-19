<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Services\SshRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PanelController extends Controller
{
    public function index(Request $request, string $section): Response
    {
        return Inertia::render('Panel', [
            'user' => $request->user()->only(['name', 'email']),
            'section' => $section,
            'sectionUrls' => [
                'summary' => route('panel', absolute: false),
                'deployments' => route('panel.deployments', absolute: false),
                'projects' => route('panel.projects', absolute: false),
                'servers' => route('panel.servers', absolute: false),
            ],
            'projects' => Project::with('servers')->orderBy('name')->get()->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'owner' => $project->owner,
                'repository' => $project->repository,
                'servers' => $project->servers->map(fn (Server $server): array => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'path' => $server->pivot->path,
                ])->values(),
                'ssh_url' => $project->ssh_url,
                'is_active' => $project->is_active,
                'has_deployments' => $project->deployments()->exists(),
            ]),
            'servers' => Server::orderBy('name')->get()->map(fn (Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'last_seen_at' => $server->last_seen_at?->format('Y-m-d H:i:s'),
                'active_target_id' => $server->active_target_id,
                'is_active' => $server->is_active,
                'has_token' => $server->agent_token_hash !== null,
                'has_history' => $server->targets()->exists(),
            ]),
            'deployments' => Deployment::with(['project', 'targets.server'])->latest('id')->limit(100)->get()->map(fn (Deployment $deployment): array => [
                'id' => $deployment->id,
                'can_delete' => $deployment->targets->every(fn (DeploymentTarget $target): bool => $target->status === 'cancelled'),
                'project_name' => $deployment->project->name,
                'ref' => $deployment->ref,
                'commit' => $deployment->commit,
                'scheduled_at' => $deployment->scheduled_at->format('Y-m-d H:i'),
                'targets' => $deployment->targets->map(fn (DeploymentTarget $target): array => [
                    'id' => $target->id,
                    'server_name' => $target->server->name,
                    'status' => $target->status,
                    'result' => $target->result,
                ])->values(),
            ]),
        ]);
    }

    public function project(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gitea_url' => ['required', 'url:http,https', 'max:255'],
            'owner' => ['required', 'regex:/^[A-Za-z0-9_.-]+$/', 'max:100'],
            'repository' => ['required', 'regex:/^[A-Za-z0-9_.-]+$/', 'max:100'],
            'gitea_token' => ['nullable', 'string', 'max:4096'],
            'ssh_url' => ['nullable', 'string', 'max:255'],
            'preserved_paths' => ['nullable', 'string', 'max:10000'],
        ]);
        $url = parse_url($data['gitea_url']);
        if (! isset($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
            throw ValidationException::withMessages(['gitea_url' => 'Use la URL base de Gitea sin credenciales, consulta ni fragmento.']);
        }
        if (! empty($data['gitea_token']) && $url['scheme'] !== 'https') {
            throw ValidationException::withMessages(['gitea_token' => 'Un token de Gitea requiere HTTPS.']);
        }
        if (! empty($data['ssh_url']) && ! SshRepository::validUrl($data['ssh_url'], $data['owner'], $data['repository'])) {
            throw ValidationException::withMessages(['ssh_url' => 'Use la URL SSH del mismo propietario y repositorio, sin contraseñas ni parámetros.']);
        }
        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', $data['preserved_paths'] ?? ''))));
        foreach ($paths as $path) {
            if (str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~(^|/)(\.{1,2}|\.git|(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.[^/]*)?)(/|$)|//|/$|[. ](/|$)|[<>:"|?*\x00-\x1f]~i', $path)) {
                throw ValidationException::withMessages(['preserved_paths' => 'Use rutas relativas con /, sin comodines, .git ni segmentos . o ...']);
            }
        }
        $data['preserved_paths'] = $paths;
        $data['gitea_url'] = rtrim($data['gitea_url'], '/');
        Project::create($data);

        return back()->with('success', 'Proyecto registrado.');
    }

    public function sshRepository(Request $request, Project $project)
    {
        $data = $request->validate(['ssh_url' => ['nullable', 'string', 'max:255']]);
        $url = $data['ssh_url'] ?? null;
        if ($url !== null && ! SshRepository::validUrl($url, $project->owner, $project->repository)) {
            throw ValidationException::withMessages(['ssh_url' => 'Use la URL SSH del mismo propietario y repositorio, sin contraseñas ni parámetros.']);
        }
        $project->update(['ssh_url' => $url]);

        return back()->with('success', 'Conexión SSH actualizada para nuevas programaciones; las existentes conservan su URL original.');
    }

    public function server(Request $request)
    {
        Server::create($request->validate(['name' => ['required', 'string', 'max:255', 'unique:servers,name']]));

        return back()->with('success', 'Servidor registrado.');
    }

    public function assignment(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'server_id' => ['required', 'exists:servers,id'],
            'path' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z]:[\\\\][^\\r\\n]+$/'],
        ]);
        if (preg_match('~(^|[\\\\/])\.{1,2}([\\\\/]|$)~', $data['path'])) {
            throw ValidationException::withMessages(['path' => 'La ruta no debe contener segmentos . o ...']);
        }
        Project::findOrFail($data['project_id'])->servers()->syncWithoutDetaching([$data['server_id'] => ['path' => $data['path']]]);

        return back()->with('success', 'Ruta del servidor guardada.');
    }

    public function deployment(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'servers' => ['required', 'array', 'min:1'],
            'servers.*' => ['required', 'distinct', 'exists:servers,id'],
            'ref' => ['required', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['required', 'timezone'],
        ]);
        $scheduledAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_at'], $data['timezone']);
        if ($scheduledAt->format('Y-m-d\TH:i') !== $data['scheduled_at']) {
            throw ValidationException::withMessages(['scheduled_at' => 'La hora no existe en la zona horaria indicada.']);
        }
        if ($scheduledAt->lte(now())) {
            throw ValidationException::withMessages(['scheduled_at' => 'La fecha debe estar en el futuro.']);
        }
        $project = Project::with('servers')->findOrFail($data['project_id']);
        if (! $project->is_active) {
            throw ValidationException::withMessages(['project_id' => 'El proyecto está desactivado.']);
        }
        $servers = $project->servers->whereIn('id', $data['servers']);
        if ($servers->count() !== count($data['servers'])) {
            throw ValidationException::withMessages(['servers' => 'Todos los servidores deben tener una ruta asignada para el proyecto.']);
        }
        if ($servers->contains(fn (Server $server): bool => ! $server->is_active)) {
            throw ValidationException::withMessages(['servers' => 'No se puede programar en un servidor desactivado.']);
        }

        $repositoryUrl = $project->ssh_url ?: $project->gitea_url.'/'.$project->owner.'/'.$project->repository.'.git';
        if ($project->ssh_url) {
            try {
                $sha = app(SshRepository::class)->resolve($project->ssh_url, $data['ref']);
            } catch (RuntimeException $e) {
                Log::warning('No se pudo resolver la referencia SSH.', ['project_id' => $project->id, 'error_type' => get_class($e)]);
                throw ValidationException::withMessages(['ref' => 'No se pudo resolver la referencia por SSH. Verifique la clave, la huella y la referencia; si hay ambigüedad use refs/heads/... o refs/tags/....']);
            }
        } else {
            if (parse_url($project->gitea_url, PHP_URL_SCHEME) !== 'https') {
                throw ValidationException::withMessages(['ref' => 'Configure SSH para el proyecto o use HTTPS confiable para consultar Gitea.']);
            }
            $url = $project->gitea_url.'/api/v1/repos/'.rawurlencode($project->owner).'/'.rawurlencode($project->repository).'/commits/'.rawurlencode($data['ref']);
            try {
                $http = Http::timeout(10)->withoutRedirecting()->acceptJson();
                if ($project->gitea_token) {
                    $http = $http->withToken($project->gitea_token);
                }
                $response = $http->get($url);
            } catch (ConnectionException $e) {
                Log::warning('No se pudo conectar con Gitea.', ['project_id' => $project->id, 'error' => $e->getMessage()]);
                throw ValidationException::withMessages(['ref' => 'No se pudo conectar con Gitea. Verifique la conexión.']);
            }
            $sha = $response->json('sha');
            if (! $response->successful() || ! is_string($sha) || ! preg_match('/^[a-f0-9]{40}$/i', $sha)) {
                Log::warning('Gitea no resolvió la referencia.', ['project_id' => $project->id, 'status' => $response->status()]);
                throw ValidationException::withMessages(['ref' => 'Gitea no pudo resolver la referencia a un commit válido.']);
            }
        }
        try {
            DB::transaction(function () use ($project, $servers, $data, $sha, $scheduledAt, $request, $repositoryUrl) {
                $deployment = Deployment::create([
                    'project_id' => $project->id,
                    'user_id' => $request->user()->id,
                    'ref' => $data['ref'],
                    'commit' => strtolower($sha),
                    'repository_url' => $repositoryUrl,
                    'scheduled_at' => $scheduledAt->utc(),
                ]);
                foreach ($servers as $server) {
                    $target = $deployment->targets()->create([
                        'server_id' => $server->id,
                        'path' => $server->pivot->path,
                        'preserved_paths' => $project->preserved_paths,
                    ]);
                    DB::table('deployment_reservations')->insert([
                        'project_id' => $project->id,
                        'server_id' => $server->id,
                        'deployment_target_id' => $target->id,
                        'commit' => strtolower($sha),
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['servers' => 'Ese commit ya tiene un despliegue pendiente o activo en alguno de los servidores seleccionados.']);
        }

        return back()->with('success', 'Programación guardada.');
    }

    public function cancelTarget(Request $request, DeploymentTarget $target)
    {
        if (! $this->cancelPendingTarget($target, $request->user()->email)) {
            throw ValidationException::withMessages(['target' => 'Solo se pueden cancelar trabajos pendientes.']);
        }

        return back()->with('success', 'Trabajo pendiente cancelado.');
    }

    public function cancelDeployment(Request $request, Deployment $deployment)
    {
        $cancelled = $deployment->targets()->get()->filter(fn (DeploymentTarget $target): bool => $this->cancelPendingTarget($target, $request->user()->email))->count();
        if ($cancelled === 0) {
            throw ValidationException::withMessages(['deployment' => 'La programación no tiene trabajos pendientes.']);
        }

        return back()->with('success', 'Programación cancelada.');
    }

    public function destroyDeployment(Deployment $deployment)
    {
        DB::transaction(function () use ($deployment) {
            $targets = $deployment->targets()->lockForUpdate()->get();
            if ($targets->contains(fn (DeploymentTarget $target): bool => $target->status !== 'cancelled')) {
                throw ValidationException::withMessages(['deployment' => 'Solo se pueden eliminar programaciones canceladas; las demás forman parte del historial.']);
            }
            DB::table('deployment_reservations')->whereIn('deployment_target_id', $targets->modelKeys())->delete();
            $deployment->targets()->delete();
            $deployment->delete();
        }, 3);

        return back()->with('success', 'Programación eliminada.');
    }

    public function toggleServer(Request $request, Server $server)
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        if (! $active && $server->active_target_id !== null) {
            throw ValidationException::withMessages(['server' => 'El servidor tiene un trabajo en ejecución; espere a que termine.']);
        }
        $server->update(['is_active' => $active]);

        return back()->with('success', $active ? 'Servidor activado.' : 'Servidor desactivado; los trabajos pendientes quedan en espera.');
    }

    public function agentToken(Server $server)
    {
        if ($server->active_target_id !== null) {
            throw ValidationException::withMessages(['server' => 'No se puede cambiar el token mientras existe un trabajo en ejecución.']);
        }
        $token = $server->rotateAgentToken();
        if ($token === null) {
            throw ValidationException::withMessages(['server' => 'El servidor ahora tiene un trabajo activo; no se cambió el token.']);
        }
        Inertia::flash('agent_token', ['server' => $server->name, 'token' => $token]);

        return back()->with('success', 'Token generado. Cópielo ahora: no se volverá a mostrar.');
    }

    public function destroyServer(Server $server)
    {
        DB::transaction(function () use ($server) {
            $server = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            if ($server->active_target_id !== null || $server->targets()->exists()) {
                throw ValidationException::withMessages(['server' => 'El servidor tiene historial de despliegues; desactívelo en lugar de eliminarlo.']);
            }
            $server->projects()->detach();
            $server->delete();
        }, 3);

        return back()->with('success', 'Servidor eliminado.');
    }

    public function toggleProject(Request $request, Project $project)
    {
        $active = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $project->update(['is_active' => $active]);

        return back()->with('success', $active ? 'Proyecto activado.' : 'Proyecto desactivado.');
    }

    public function destroyProject(Project $project)
    {
        DB::transaction(function () use ($project) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($project->deployments()->exists()) {
                throw ValidationException::withMessages(['project' => 'El proyecto tiene historial de despliegues; desactívelo en lugar de eliminarlo.']);
            }
            $project->servers()->detach();
            $project->delete();
        }, 3);

        return back()->with('success', 'Proyecto eliminado.');
    }

    public function removeAssignment(Project $project, Server $server)
    {
        $inUse = DeploymentTarget::where('server_id', $server->id)
            ->whereIn('status', ['pending', 'running'])
            ->whereHas('deployment', fn ($query) => $query->where('project_id', $project->id))
            ->exists();
        if ($inUse) {
            throw ValidationException::withMessages(['assignment' => 'Hay trabajos pendientes o en ejecución para esta ruta; cancélelos primero.']);
        }
        $project->servers()->detach($server->id);

        return back()->with('success', 'Ruta eliminada del proyecto.');
    }

    private function cancelPendingTarget(DeploymentTarget $target, string $email): bool
    {
        return DB::transaction(function () use ($target, $email) {
            Server::whereKey($target->server_id)->lockForUpdate()->firstOrFail();
            $target = DeploymentTarget::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($target->status !== 'pending') {
                return false;
            }
            $target->status = 'cancelled';
            $target->result = 'Cancelado por '.$email;
            $target->finished_at = now();
            $target->save();
            DB::table('deployment_reservations')->where('deployment_target_id', $target->id)->delete();

            return true;
        }, 3);
    }
}
