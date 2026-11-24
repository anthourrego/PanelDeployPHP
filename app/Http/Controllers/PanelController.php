<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureActiveUser;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\ProjectTagSyncService;
use App\Services\SshRepository;
use App\Services\WindowsAgentDownloads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PanelController extends Controller
{
    public function index(Request $request, string $section, WindowsAgentDownloads $agentDownloads, ProjectTagSyncService $tagSync): Response
    {
        $serverVersions = $section === 'projects' ? $this->projectServerVersions() : collect();
        $filters = $section === 'deployments' ? $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'server_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'running', 'succeeded', 'failed', 'uncertain', 'cancelled'])],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1753-01-01', 'before:9999-12-31'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1753-01-01', 'before:9999-12-31', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
        ], [
            'date_from.after_or_equal' => 'La fecha desde debe ser igual o posterior al 1753-01-01.',
            'date_from.before' => 'La fecha desde debe ser igual o anterior al 9999-12-30.',
            'date_to.after_or_equal' => 'La fecha hasta debe ser igual o posterior al 1753-01-01 y a la fecha desde.',
            'date_to.before' => 'La fecha hasta debe ser igual o anterior al 9999-12-30.',
        ], [
            'project_id' => 'proyecto',
            'server_id' => 'servidor',
            'status' => 'estado',
            'date_from' => 'fecha desde',
            'date_to' => 'fecha hasta',
        ]) : [];

        $history = null;
        if ($section === 'deployments') {
            $query = Deployment::with(['project', 'targets.server'])->latest('id');

            if (! empty($filters['project_id'])) {
                $query->where('project_id', $filters['project_id']);
            }
            if (! empty($filters['server_id']) || ! empty($filters['status'])) {
                $query->whereHas('targets', function (Builder $targets) use ($filters): void {
                    if (! empty($filters['server_id'])) {
                        $targets->where('server_id', $filters['server_id']);
                    }
                    if (! empty($filters['status'])) {
                        $targets->where('status', $filters['status']);
                    }
                });
            }
            if (! empty($filters['date_from'])) {
                $query->where('scheduled_at', '>=', $filters['date_from'].' 00:00:00');
            }
            if (! empty($filters['date_to'])) {
                $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'], 'UTC')->addDay();
                $query->where('scheduled_at', '<', $end->format('Y-m-d H:i:s'));
            }

            $history = $query->paginate(20)->appends($filters)->through(fn (Deployment $deployment): array => $this->serializeDeployment($deployment));
        }

        return Inertia::render('Panel', [
            'user' => $request->user()->only(['name', 'username', 'email']),
            'profileUpdateUrl' => route('profile.update', absolute: false),
            'passwordUpdateUrl' => route('profile.password.update', absolute: false),
            'appVersion' => config('app.version'),
            'projectTagsSyncAllUrl' => route('projects.tags.sync-all', absolute: false),
            'deploymentTimezone' => config('app.deployment_timezone'),
            'canManageUsers' => $request->user()->is_admin,
            'section' => $section,
            'agentDownloads' => $section === 'downloads' ? fn (): array => $agentDownloads->links() : null,
            'users' => $section === 'users' ? fn (): array => User::query()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'username', 'email', 'is_admin', 'is_active'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'is_admin' => $user->is_admin,
                    'is_active' => $user->is_active,
                    'update_url' => route('users.update', $user, absolute: false),
                    'active_url' => route('users.active', $user, absolute: false),
                ])
                ->all() : [],
            'sectionUrls' => [
                'summary' => route('panel', absolute: false),
                'deployments' => route('panel.deployments', absolute: false),
                'projects' => route('panel.projects', absolute: false),
                'servers' => route('panel.servers', absolute: false),
                'downloads' => route('panel.downloads', absolute: false),
                'users' => route('panel.users', absolute: false),
            ],
            'projects' => Project::with(['servers', 'latestDeployment.targets.server'])->orderBy('name')->get()->map(function (Project $project) use ($serverVersions, $tagSync): array {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'gitea_url' => $project->gitea_url,
                    'owner' => $project->owner,
                    'repository' => $project->repository,
                    'references_url' => route('projects.references', $project, absolute: false),
                    'servers' => $project->servers->map(function (Server $server) use ($project, $serverVersions, $tagSync): array {
                        $version = $serverVersions->get("{$project->id}:{$server->id}", [
                            'last_successful_deployment' => null,
                            'deployment_uncertain' => false,
                        ]);

                        return [
                            'id' => $server->id,
                            'name' => $server->name,
                            'path' => $server->pivot->path,
                            'is_active' => $server->is_active,
                            ...$version,
                            ...$this->projectVersionStatus($project, $version, $tagSync),
                        ];
                    })->values(),
                    'ssh_url' => $project->ssh_url,
                    'preserved_paths' => $project->preserved_paths,
                    'has_gitea_token' => $project->getRawOriginal('gitea_token') !== null,
                    'is_active' => $project->is_active,
                    'has_active_deployments' => $project->deployments()
                        ->whereHas('targets', fn (Builder $targets): Builder => $targets->whereIn('status', ['pending', 'running']))
                        ->exists(),
                    'latest_tag' => $project->latest_tag,
                    'latest_tag_commit' => $project->latest_tag_commit,
                    'latest_tag_synced_at' => $project->latest_tag_synced_at?->format('Y-m-d H:i'),
                    'latest_tag_sync_error' => $project->latest_tag_sync_error,
                    'latest_deployment' => $project->latestDeployment === null ? null : [
                        'ref' => $project->latestDeployment->ref,
                        'commit' => $project->latestDeployment->commit,
                        'created_at' => $project->latestDeployment->created_at->format('Y-m-d H:i'),
                        'scheduled_at' => $project->latestDeployment->scheduled_at->format('Y-m-d H:i'),
                        'targets' => $project->latestDeployment->targets->map(fn (DeploymentTarget $target): array => [
                            'server_name' => $target->server->name,
                            'status' => $target->status,
                        ])->values(),
                    ],
                ];
            }),
            'trashedProjects' => $section === 'projects'
                ? Project::onlyTrashed()->orderBy('name')->get(['id', 'name', 'owner', 'repository'])->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'owner' => $project->owner,
                    'repository' => $project->repository,
                ])->all()
                : [],
            'servers' => Server::orderBy('name')->get()->map(fn (Server $server): array => [
                'id' => $server->id,
                'name' => $server->name,
                'last_seen_at' => $server->last_seen_at?->format('Y-m-d H:i:s'),
                'active_target_id' => $server->active_target_id,
                'is_active' => $server->is_active,
                'has_token' => $server->agent_token_hash !== null,
                'has_history' => $server->targets()->exists(),
            ]),
            'deployments' => Deployment::with(['project', 'targets.server'])->latest('id')->limit(100)->get()->map(fn (Deployment $deployment): array => $this->serializeDeployment($deployment)),
            'deploymentFilters' => $filters,
            'deploymentHistory' => $history,
        ]);
    }

    private function serializeDeployment(Deployment $deployment): array
    {
        return [
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
        ];
    }

    /**
     * @return Collection<string, array{last_successful_deployment: array{ref: string, commit: string, finished_at: string}|null, deployment_uncertain: bool}>
     */
    private function projectServerVersions(): Collection
    {
        $finishedTargets = DB::table('deployment_targets')
            ->join('deployments', 'deployments.id', '=', 'deployment_targets.deployment_id')
            ->whereColumn('deployments.project_id', 'project_server.project_id')
            ->whereColumn('deployment_targets.server_id', 'project_server.server_id')
            ->whereColumn('deployment_targets.path', 'project_server.path')
            ->whereNotNull('deployment_targets.finished_at')
            ->orderByDesc('deployment_targets.finished_at')
            ->orderByDesc('deployment_targets.id')
            ->limit(1);

        $assignments = DB::table('project_server')
            ->select(['project_id', 'server_id'])
            ->addSelect([
                'success_id' => (clone $finishedTargets)->select('deployment_targets.id')->where('deployment_targets.status', 'succeeded'),
                'latest_outcome' => (clone $finishedTargets)->select('deployment_targets.status')->whereIn('deployment_targets.status', ['succeeded', 'uncertain']),
            ]);

        return DB::query()->fromSub($assignments, 'assignments')
            ->leftJoin('deployment_targets as successful', 'successful.id', '=', 'assignments.success_id')
            ->leftJoin('deployments', 'deployments.id', '=', 'successful.deployment_id')
            ->get(['assignments.project_id', 'assignments.server_id', 'assignments.latest_outcome', 'deployments.ref', 'deployments.commit', 'successful.finished_at'])
            ->mapWithKeys(fn (object $row): array => ["{$row->project_id}:{$row->server_id}" => [
                'last_successful_deployment' => $row->finished_at === null ? null : [
                    'ref' => $row->ref,
                    'commit' => $row->commit,
                    'finished_at' => CarbonImmutable::parse($row->finished_at)->format('Y-m-d H:i'),
                ],
                'deployment_uncertain' => $row->latest_outcome === 'uncertain',
            ]]);
    }

    /**
     * @param  array{last_successful_deployment: array{ref: string, commit: string, finished_at: string}|null, deployment_uncertain: bool}  $serverVersion
     * @return array{version_status: string, version_status_reason: string|null}
     */
    private function projectVersionStatus(Project $project, array $serverVersion, ProjectTagSyncService $tagSync): array
    {
        $deployment = $serverVersion['last_successful_deployment'];
        if ($project->latest_tag_sync_error !== null) {
            return ['version_status' => 'unknown', 'version_status_reason' => 'No se pudieron sincronizar las etiquetas del repositorio.'];
        }

        if ($project->latest_tag_synced_at === null) {
            return ['version_status' => 'unknown', 'version_status_reason' => 'Aún no se han sincronizado etiquetas para comparar.'];
        }

        if ($project->latest_tag === null || $project->latest_tag_commit === null) {
            return ['version_status' => 'unknown', 'version_status_reason' => 'No hay una etiqueta SemVer disponible para comparar.'];
        }

        if ($serverVersion['deployment_uncertain']) {
            return ['version_status' => 'unknown', 'version_status_reason' => 'El último despliegue no pudo confirmarse.'];
        }

        if ($deployment === null) {
            return ['version_status' => 'unknown', 'version_status_reason' => 'No hay un despliegue exitoso confirmado para esta ruta.'];
        }

        if (hash_equals($project->latest_tag_commit, $deployment['commit'])) {
            return ['version_status' => 'current', 'version_status_reason' => null];
        }

        try {
            if ($project->ssh_url) {
                $status = $tagSync->compareSshCommits($project, $deployment['commit'], $project->latest_tag_commit);
                if ($status !== 'diverged') {
                    return ['version_status' => $status, 'version_status_reason' => null];
                }
            } else {
                if ($tagSync->isAncestor($project, $deployment['commit'], $project->latest_tag_commit)) {
                    return ['version_status' => 'behind', 'version_status_reason' => null];
                }

                if ($tagSync->isAncestor($project, $project->latest_tag_commit, $deployment['commit'])) {
                    return ['version_status' => 'ahead', 'version_status_reason' => null];
                }
            }
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            Log::warning('No se pudo comparar la versión desplegada con la disponible.', [
                'project_id' => $project->id,
                'deployed_commit' => $deployment['commit'],
                'available_commit' => $project->latest_tag_commit,
                'error_type' => get_class($exception),
            ]);

            return ['version_status' => 'unknown', 'version_status_reason' => 'No se pudo verificar la relación de commits con el repositorio.'];
        }

        return [
            'version_status' => 'unknown',
            'version_status_reason' => 'Los commits no pertenecen a la misma línea de desarrollo; puede haber divergencia.',
        ];
    }

    public function createUser(Request $request): RedirectResponse
    {
        $data = $this->validateUser($request);
        $user = new User(Arr::except($data, ['is_admin']));
        $user->is_admin = (bool) ($data['is_admin'] ?? false);
        $user->save();

        return back()->with('success', 'Usuario creado.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validateUser($request, $user, profileOnly: true);
        $user->update(Arr::only($data, ['name', 'username', 'email']));

        return back()->with('success', 'Perfil actualizado.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => ['bail', 'required', 'string', 'confirmed', Password::min(12)],
        ], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.confirmed' => 'La confirmación no coincide con la nueva contraseña.',
        ]);
        if (str_contains($data['password'], "\0") || (config('hashing.driver') === 'bcrypt' && strlen($data['password']) > 72)) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña contiene caracteres no admitidos o es demasiado larga.']);
        }

        $user = $request->user();
        $nextSessionVersion = $user->session_version + 1;
        $updated = User::whereKey($user->getKey())
            ->where('is_active', true)
            ->where('session_version', $user->session_version)
            ->update([
                'password' => Hash::make($data['password']),
                'session_version' => $nextSessionVersion,
                'remember_token' => null,
            ]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['current_password' => 'El acceso a la cuenta cambió. Vuelve a iniciar sesión.']);
        }

        $request->session()->regenerate(true);
        $request->session()->put(EnsureActiveUser::SESSION_VERSION_KEY, $nextSessionVersion);

        return back()->with('success', 'Contraseña actualizada.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateUser($request, $user);
        $isAdmin = (bool) $data['is_admin'];
        $this->changeUserAccess($request->user(), $user, ['is_admin' => $isAdmin], Arr::except($data, ['is_admin']));

        $section = $request->user()->is($user) && ! $isAdmin ? 'panel' : 'panel.users';

        return redirect()->route($section)->with('success', 'Usuario actualizado.');
    }

    public function toggleUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $isActive = (bool) $data['is_active'];
        $this->changeUserAccess($request->user(), $user, ['is_active' => $isActive]);

        $section = $request->user()->is($user) && ! $isActive ? 'panel' : 'panel.users';

        return redirect()->route($section)->with('success', $isActive ? 'Usuario reactivado.' : 'Usuario desactivado.');
    }

    /**
     * @return array{name: string, username: string, email: string, password?: string, is_admin?: bool|int|string}
     */
    private function validateUser(Request $request, ?User $user = null, bool $profileOnly = false): array
    {
        $username = $request->input('username');
        if (is_string($username)) {
            $request->merge(['username' => strtolower($username)]);
        }

        $uniqueUsername = Rule::unique('users', 'username');
        $uniqueEmail = Rule::unique('users', 'email');
        if ($user !== null) {
            $uniqueUsername->ignore($user);
            $uniqueEmail->ignore($user);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.-]+\z/D', $uniqueUsername],
            'email' => ['required', 'email', 'max:255', $uniqueEmail],
        ];
        if (! $profileOnly) {
            $rules['is_admin'] = [$user === null ? 'sometimes' : 'required', 'boolean'];
        }
        if ($user === null) {
            $rules['password'] = ['required', 'confirmed', Password::min(12)];
        }

        return $request->validate($rules, [
            'is_admin.boolean' => 'Indique si la cuenta debe ser de administrador.',
        ]);
    }

    /**
     * @param  array{is_admin?: bool, is_active?: bool}  $access
     * @param  array{name?: string, username?: string, email?: string}  $profile
     */
    private function changeUserAccess(User $actor, User $target, array $access, array $profile = []): void
    {
        DB::transaction(function () use ($actor, $target, $access, $profile): void {
            $accounts = User::query()
                ->whereKey([$actor->id, $target->id])
                ->orWhere(fn ($query) => $query->where('is_admin', true)->where('is_active', true))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $currentActor = $accounts->get($actor->id);
            abort_unless($currentActor?->is_admin && $currentActor->is_active && $currentActor->session_version === $actor->session_version, 403);
            $user = $accounts->get($target->id);
            abort_if($user === null, 404);

            $isAdmin = $access['is_admin'] ?? $user->is_admin;
            $isActive = $access['is_active'] ?? $user->is_active;
            if ($user->is_admin && $user->is_active && (! $isAdmin || ! $isActive)
                && ! $accounts->contains(fn (User $account): bool => ! $account->is($user) && $account->is_admin && $account->is_active)) {
                $field = array_key_exists('is_active', $access) ? 'is_active' : 'is_admin';
                throw ValidationException::withMessages([$field => 'Debe quedar al menos un administrador activo.']);
            }

            if ($user->is_active && ! $isActive) {
                $user->session_version++;
            }

            $user->fill($profile);
            $user->is_admin = $isAdmin;
            $user->is_active = $isActive;
            $user->save();
        }, 3);
    }

    public function downloadWindowsAgent(string $package, WindowsAgentDownloads $agentDownloads): BinaryFileResponse
    {
        $file = $agentDownloads->file($package);
        abort_if($file === null, 404, 'El archivo solicitado no está disponible.');

        return response()->download($file, basename($file), [
            'Content-Type' => str_ends_with($file, '.zip') ? 'application/zip' : 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }

    public function project(Request $request, ProjectTagSyncService $tagSync): RedirectResponse
    {
        $data = $this->validatedProjectData($request);
        $project = new Project($data);
        $this->saveProject($project);
        $synced = $tagSync->sync($project);

        return $this->tagSyncResponse(
            $project,
            $synced,
            'Proyecto registrado.',
            'Proyecto registrado, pero no fue posible sincronizar sus tags. Revise el estado del proyecto.',
        );
    }

    public function updateProject(Request $request, Project $project, ProjectTagSyncService $tagSync): RedirectResponse
    {
        $data = $this->validatedProjectData($request);
        if (! isset($data['gitea_token'])) {
            if ($project->gitea_token !== null && parse_url($data['gitea_url'], PHP_URL_SCHEME) !== 'https') {
                throw ValidationException::withMessages(['gitea_token' => 'Un token de Gitea requiere HTTPS.']);
            }
            unset($data['gitea_token']);
        }

        $project->fill($data);
        $shouldSyncTags = $project->isDirty(['gitea_url', 'owner', 'repository', 'ssh_url', 'gitea_token']);
        $this->saveProject($project);

        if (! $shouldSyncTags) {
            return back()->with('success', 'Proyecto actualizado.');
        }

        return $this->tagSyncResponse(
            $project,
            $tagSync->sync($project),
            'Proyecto actualizado.',
            'Proyecto actualizado, pero no fue posible sincronizar sus tags. Revise el estado del proyecto.',
        );
    }

    /**
     * @return array{name: string, gitea_url: string, owner: string, repository: string, gitea_token?: string|null, ssh_url?: string|null, preserved_paths: list<string>}
     */
    private function validatedProjectData(Request $request): array
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

        return $data;
    }

    private function saveProject(Project $project): void
    {
        $this->validateUniqueProject($project);

        try {
            $project->save();
        } catch (QueryException $exception) {
            $sqlServerDuplicate = $project->getConnection()->getDriverName() === 'sqlsrv'
                && in_array((int) ($exception->errorInfo[1] ?? 0), [2601, 2627], true);
            if ($exception instanceof UniqueConstraintViolationException || $sqlServerDuplicate) {
                $this->validateUniqueProject($project);
            }

            throw $exception;
        }
    }

    private function validateUniqueProject(Project $project): void
    {
        $otherProjects = Project::withTrashed()
            ->when($project->exists, fn (Builder $query) => $query->where('id', '<>', $project->getKey()));

        $matchingName = (clone $otherProjects)->where('name', $project->name)->first();
        $matchingRepository = (clone $otherProjects)
            ->where('gitea_url', $project->gitea_url)
            ->where('owner', $project->owner)
            ->where('repository', $project->repository)
            ->first();

        $errors = [];
        if ($matchingName !== null) {
            $errors['name'] = $matchingName->trashed()
                ? 'Ya existe un proyecto con este nombre en la papelera. Restáurelo en lugar de crear otro.'
                : 'Ya existe un proyecto con este nombre.';
        }
        if ($matchingRepository !== null) {
            $errors['repository'] = $matchingRepository->trashed()
                ? 'Este repositorio ya pertenece a un proyecto en la papelera. Restáurelo en lugar de crear otro.'
                : 'Este repositorio ya está registrado para esta URL de Gitea y propietario.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function sshRepository(Request $request, Project $project, ProjectTagSyncService $tagSync): RedirectResponse
    {
        $data = $request->validate(['ssh_url' => ['nullable', 'string', 'max:255']]);
        $url = $data['ssh_url'] ?? null;
        if ($url !== null && ! SshRepository::validUrl($url, $project->owner, $project->repository)) {
            throw ValidationException::withMessages(['ssh_url' => 'Use la URL SSH del mismo propietario y repositorio, sin contraseñas ni parámetros.']);
        }
        if ($project->ssh_url === $url) {
            return back()->with('success', 'Conexión SSH sin cambios; use Sincronizar tags para consultar de nuevo.');
        }

        $project->update(['ssh_url' => $url]);
        $synced = $tagSync->sync($project);

        return $this->tagSyncResponse(
            $project,
            $synced,
            'Conexión SSH actualizada para nuevas programaciones.',
            'Conexión SSH actualizada, pero no fue posible sincronizar sus tags. Revise el estado del proyecto.',
        );
    }

    public function syncProjectTags(Project $project, ProjectTagSyncService $tagSync): RedirectResponse
    {
        return $this->tagSyncResponse(
            $project,
            $tagSync->sync($project),
            'Sincronización de tags completada.',
            'No fue posible sincronizar los tags. Revise el estado del proyecto.',
        );
    }

    public function syncAllProjectTags(ProjectTagSyncService $tagSync): RedirectResponse
    {
        $synced = 0;
        $failed = 0;

        foreach (Project::query()->lazyById() as $project) {
            if ($tagSync->sync($project)) {
                $synced++;
            } else {
                $failed++;
            }
        }

        $total = $synced + $failed;
        $response = redirect()->route('panel.projects');
        if ($total === 0) {
            return $response->with('success', 'No hay proyectos para sincronizar.');
        }

        $summary = "Se sincronizaron {$synced} de {$total} proyectos.";
        if ($failed > 0) {
            return $response->with('error', "{$summary} No se pudieron consultar {$failed}. Revise las tarjetas de los proyectos.");
        }

        return $response->with('success', $summary);
    }

    private function tagSyncResponse(Project $project, bool $synced, string $successMessage, string $errorMessage): RedirectResponse
    {
        if (! $synced) {
            return back()->with('error', $errorMessage);
        }

        if ($project->latest_tag === null) {
            return back()->with('success', $successMessage.' No se encontraron tags SemVer.');
        }

        return back()->with('success', $successMessage.' Última versión disponible: '.$project->latest_tag.'.');
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

    public function projectReferences(Project $project, ProjectTagSyncService $repository): JsonResponse
    {
        abort_unless($project->is_active, 404);

        try {
            return response()->json($repository->references($project))
                ->header('Cache-Control', 'no-store');
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            Log::warning('No se pudieron consultar las referencias para el despliegue.', [
                'project_id' => $project->id,
                'error_type' => get_class($exception),
            ]);

            return response()->json(['message' => 'No se pudieron cargar las ramas y etiquetas. Verifique la conexión con el repositorio.'], 502);
        }
    }

    public function deployment(Request $request, ProjectTagSyncService $repository): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'servers' => ['required', 'array', 'min:1'],
            'servers.*' => ['required', 'distinct', 'exists:servers,id'],
            'ref' => ['required', 'string', 'max:255', 'regex:~\Arefs/(?:heads|tags)/[^\x00-\x20\x7f]+\z~'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['required', 'timezone', Rule::in([config('app.deployment_timezone')])],
        ], [
            'ref.regex' => 'Seleccione una rama o etiqueta del proyecto.',
            'timezone.in' => 'La zona horaria de programación cambió. Recargue la página antes de continuar.',
        ]);
        $scheduledAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['scheduled_at'], config('app.deployment_timezone'));
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
        try {
            $sha = $repository->resolve($project, $data['ref']);
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            Log::warning('No se pudo resolver la referencia del despliegue.', [
                'project_id' => $project->id,
                'error_type' => get_class($exception),
            ]);
            throw ValidationException::withMessages(['ref' => 'No se pudo verificar la rama o etiqueta seleccionada. Verifique que siga disponible en el repositorio.']);
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
                Project::query()
                    ->whereKey($project->id)
                    ->where(function ($query) use ($deployment) {
                        $query->whereNull('latest_deployment_id')
                            ->orWhere('latest_deployment_id', '<', $deployment->id);
                    })
                    ->update([
                        'latest_deployment_id' => $deployment->id,
                        'updated_at' => now(),
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
            $projectId = $deployment->project_id;
            $deployment->targets()->delete();
            $deployment->delete();

            $latestDeploymentId = Deployment::query()
                ->where('project_id', $projectId)
                ->max('id');

            Project::query()
                ->whereKey($projectId)
                ->where(function ($query) use ($deployment) {
                    $query->where('latest_deployment_id', $deployment->id)
                        ->orWhereNull('latest_deployment_id');
                })
                ->update([
                    'latest_deployment_id' => $latestDeploymentId,
                    'updated_at' => now(),
                ]);
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

    public function destroyProject(Project $project): RedirectResponse
    {
        DB::transaction(function () use ($project) {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $hasActiveDeployments = $project->deployments()
                ->whereHas('targets', fn (Builder $targets): Builder => $targets->whereIn('status', ['pending', 'running']))
                ->exists();
            if ($hasActiveDeployments) {
                throw ValidationException::withMessages(['project' => 'El proyecto tiene despliegues pendientes o en ejecución; cancélelos antes de enviarlo a la papelera.']);
            }
            $project->delete();
        }, 3);

        return back()->with('success', 'Proyecto movido a la papelera. Puedes restaurarlo cuando quieras.');
    }

    public function restoreProject(Project $project): RedirectResponse
    {
        DB::transaction(function () use ($project): void {
            $project = Project::onlyTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $project->restore();
        }, 3);

        return back()->with('success', 'Proyecto restaurado.');
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
