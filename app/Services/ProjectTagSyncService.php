<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProjectTagSyncService
{
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 20;

    private const SELECTOR_TAG_LIMIT = 20;

    public function __construct(private SshRepository $sshRepository) {}

    public function sync(Project $project): bool
    {
        try {
            $tags = iterator_to_array($this->repositoryReferences($project, 'tags'), false);
        } catch (ConnectionException|RequestException|RuntimeException $exception) {
            Log::warning('No se pudieron sincronizar los tags del proyecto.', [
                'project_id' => $project->id,
                'error_type' => get_class($exception),
            ]);
            $project->forceFill([
                'latest_tag_sync_error' => 'No fue posible consultar los tags. Verifique la conexión, las credenciales y la URL del repositorio.',
            ])->save();

            return false;
        }

        $latestTag = $this->highestSemverTag($tags);
        $project->forceFill([
            'latest_tag' => $latestTag['name'] ?? null,
            'latest_tag_commit' => $latestTag['commit'] ?? null,
            'latest_tag_synced_at' => now(),
            'latest_tag_sync_error' => null,
        ])->save();

        return true;
    }

    /**
     * @return array{branches: list<string>, tags: list<string>}
     */
    public function references(Project $project): array
    {
        $result = [];
        foreach (['branches' => 'refs/heads/', 'tags' => 'refs/tags/'] as $kind => $prefix) {
            $names = [];
            foreach ($this->repositoryReferences($project, $kind) as $reference) {
                if (mb_strlen($prefix.$reference['name']) <= 255) {
                    $names[] = $reference['name'];
                }
            }
            natcasesort($names);
            $orderedNames = array_reverse(array_unique($names));
            $result[$kind] = $kind === 'tags' ? array_slice($orderedNames, 0, self::SELECTOR_TAG_LIMIT) : $orderedNames;
        }

        return $result;
    }

    public function resolve(Project $project, string $ref): string
    {
        if ($project->ssh_url) {
            if (! SshRepository::validUrl($project->ssh_url, $project->owner, $project->repository)) {
                throw new RuntimeException('La URL SSH no corresponde al repositorio configurado.');
            }

            return $this->sshRepository->resolve($project->ssh_url, $ref);
        }

        [, $namespace, $name] = explode('/', $ref, 3);
        foreach ($this->repositoryReferences($project, $namespace === 'tags' ? 'tags' : 'branches') as $reference) {
            if ($reference['name'] === $name) {
                return $reference['commit'];
            }
        }

        throw new RuntimeException('La referencia no existe en el repositorio.');
    }

    public function isAncestor(Project $project, string $ancestorCommit, string $descendantCommit): bool
    {
        $ancestorCommit = strtolower($ancestorCommit);
        $descendantCommit = strtolower($descendantCommit);
        if (! preg_match('/\A[a-f0-9]{40}\z/D', $ancestorCommit) || ! preg_match('/\A[a-f0-9]{40}\z/D', $descendantCommit)) {
            throw new RuntimeException('Los commits no tienen un formato válido.');
        }

        if ($ancestorCommit === $descendantCommit) {
            return true;
        }

        if (parse_url($project->gitea_url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('La comparación de commits requiere una URL de Gitea con HTTPS.');
        }

        $repositoryKey = hash('sha256', implode('/', [
            strtolower($project->gitea_url),
            $project->owner,
            $project->repository,
        ]));
        $cacheKey = "project-commit-ancestry:{$repositoryKey}:{$ancestorCommit}:{$descendantCommit}";
        $comparison = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($project, $ancestorCommit, $descendantCommit): array {
            $url = $project->gitea_url.'/api/v1/repos/'
                .rawurlencode($project->owner).'/'
                .rawurlencode($project->repository).'/compare/'
                .$ancestorCommit.'...'.$descendantCommit;

            $request = Http::connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->acceptJson();

            if ($project->gitea_token) {
                $request = $request->withHeaders(['Authorization' => 'token '.$project->gitea_token]);
            }

            $response = $request->get($url, ['files' => false, 'verification' => false])->throw();
            $comparison = json_decode($response->body());
            if (! is_object($comparison) || ! is_array($comparison->commits ?? null)) {
                throw new RuntimeException('Gitea devolvió una comparación de commits inválida.');
            }

            foreach ($comparison->commits as $commit) {
                if (! is_object($commit) || ! is_array($commit->parents ?? [])) {
                    throw new RuntimeException('Gitea devolvió un commit inválido en la comparación.');
                }

                foreach ($commit->parents ?? [] as $parent) {
                    $sha = is_object($parent) ? ($parent->sha ?? null) : null;
                    if (! is_string($sha) || ! preg_match('/\A[a-f0-9]{40}\z/iD', $sha)) {
                        throw new RuntimeException('Gitea devolvió un padre de commit inválido.');
                    }

                    if (hash_equals($ancestorCommit, strtolower($sha))) {
                        return ['is_ancestor' => true];
                    }
                }
            }

            return ['is_ancestor' => false];
        });

        return $comparison['is_ancestor'];
    }

    /**
     * @return 'behind'|'ahead'|'diverged'
     */
    public function compareSshCommits(Project $project, string $deployedCommit, string $availableCommit): string
    {
        $repositoryKey = hash('sha256', implode('/', [$project->ssh_url, $project->owner, $project->repository]));
        $cacheKey = 'project-ssh-commit-comparison:'.$repositoryKey.':'.strtolower($deployedCommit).':'.strtolower($availableCommit);

        return Cache::remember($cacheKey, now()->addMinutes(5), fn (): string => $this->sshRepository->compareCommits(
            $project->ssh_url,
            $project->owner,
            $project->repository,
            $deployedCommit,
            $availableCommit,
        ));
    }

    /**
     * @param  'branches'|'tags'  $kind
     * @return iterable<array{name: string, commit: string}>
     */
    private function repositoryReferences(Project $project, string $kind): iterable
    {
        if (! $project->ssh_url) {
            return $this->giteaReferences($project, $kind);
        }

        return $kind === 'tags'
            ? $this->sshRepository->listTags($project->ssh_url, $project->owner, $project->repository)
            : $this->sshRepository->listBranches($project->ssh_url, $project->owner, $project->repository);
    }

    /**
     * @param  'branches'|'tags'  $kind
     * @return iterable<array{name: string, commit: string}>
     */
    private function giteaReferences(Project $project, string $kind): iterable
    {
        if (parse_url($project->gitea_url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('La consulta de referencias por API requiere HTTPS o una URL SSH.');
        }

        $url = $project->gitea_url.'/api/v1/repos/'
            .rawurlencode($project->owner).'/'
            .rawurlencode($project->repository).'/'.$kind;

        for ($page = 1; $page <= self::MAX_PAGES + 1; $page++) {
            $request = Http::connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->acceptJson();

            if ($project->gitea_token) {
                $request = $request->withHeaders(['Authorization' => 'token '.$project->gitea_token]);
            }

            $response = $request->get($url, ['limit' => self::PAGE_SIZE, 'page' => $page])->throw();
            $references = json_decode($response->body());

            if (! $response->successful() || ! is_array($references)) {
                throw new RuntimeException('Gitea devolvió una lista de referencias inválida.');
            }

            foreach ($references as $reference) {
                if (! is_object($reference)) {
                    throw new RuntimeException('Gitea devolvió una referencia inválida.');
                }

                $name = $reference->name ?? null;
                $commitData = $reference->commit ?? null;
                $commit = is_object($commitData)
                    ? ($kind === 'tags' ? ($commitData->sha ?? null) : ($commitData->id ?? null))
                    : null;

                if (! is_string($name) || $name === '' || ! is_string($commit) || ! preg_match('/\A[a-f0-9]{40}\z/iD', $commit)) {
                    throw new RuntimeException('Gitea devolvió una referencia sin un commit válido.');
                }

                yield ['name' => $name, 'commit' => strtolower($commit)];
            }

            if (count($references) < self::PAGE_SIZE) {
                return;
            }

            if ($page > self::MAX_PAGES) {
                throw new RuntimeException('El repositorio contiene demasiadas referencias para una sincronización segura.');
            }
        }

        throw new RuntimeException('No se pudo completar la consulta paginada de referencias.');
    }

    /**
     * @param  list<array{name: string, commit: string}>  $tags
     * @return array{name: string, commit: string}|null
     */
    private function highestSemverTag(array $tags): ?array
    {
        $latestTag = null;
        $latestVersion = null;

        foreach ($tags as $tag) {
            $version = $this->parseSemver($tag['name']);
            if ($version === null) {
                continue;
            }

            $comparison = $latestVersion === null ? 1 : $this->compareSemver($version, $latestVersion);
            if ($comparison > 0 || ($comparison === 0 && strcmp($tag['name'], $latestTag['name']) < 0)) {
                $latestTag = $tag;
                $latestVersion = $version;
            }
        }

        return $latestTag;
    }

    /**
     * @return array{major: string, minor: string, patch: string, prerelease: list<string>}|null
     */
    private function parseSemver(string $tagName): ?array
    {
        if (! preg_match('/\Av?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/D', $tagName, $matches)) {
            return null;
        }

        $prerelease = isset($matches[4]) ? explode('.', $matches[4]) : [];
        foreach ($prerelease as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') {
                return null;
            }
        }

        return [
            'major' => $matches[1],
            'minor' => $matches[2],
            'patch' => $matches[3],
            'prerelease' => $prerelease,
        ];
    }

    /**
     * @param  array{major: string, minor: string, patch: string, prerelease: list<string>}  $left
     * @param  array{major: string, minor: string, patch: string, prerelease: list<string>}  $right
     */
    private function compareSemver(array $left, array $right): int
    {
        foreach (['major', 'minor', 'patch'] as $component) {
            $comparison = $this->compareNumericIdentifiers($left[$component], $right[$component]);
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        if ($left['prerelease'] === []) {
            return $right['prerelease'] === [] ? 0 : 1;
        }

        if ($right['prerelease'] === []) {
            return -1;
        }

        $identifierCount = min(count($left['prerelease']), count($right['prerelease']));
        for ($index = 0; $index < $identifierCount; $index++) {
            $leftIdentifier = $left['prerelease'][$index];
            $rightIdentifier = $right['prerelease'][$index];
            $leftIsNumeric = ctype_digit($leftIdentifier);
            $rightIsNumeric = ctype_digit($rightIdentifier);

            if ($leftIsNumeric && $rightIsNumeric) {
                $comparison = $this->compareNumericIdentifiers($leftIdentifier, $rightIdentifier);
            } elseif ($leftIsNumeric !== $rightIsNumeric) {
                $comparison = $leftIsNumeric ? -1 : 1;
            } else {
                $comparison = strcmp($leftIdentifier, $rightIdentifier) <=> 0;
            }

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return count($left['prerelease']) <=> count($right['prerelease']);
    }

    private function compareNumericIdentifiers(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: (strcmp($left, $right) <=> 0);
    }
}
