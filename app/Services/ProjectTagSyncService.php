<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProjectTagSyncService
{
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 20;

    public function __construct(private SshRepository $sshRepository) {}

    public function sync(Project $project): bool
    {
        try {
            $tags = $project->ssh_url
                ? $this->sshTags($project)
                : $this->giteaTags($project);
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
     * @return list<array{name: string, commit: string}>
     */
    private function sshTags(Project $project): array
    {
        return $this->sshRepository->listTags(
            $project->ssh_url,
            $project->owner,
            $project->repository,
        );
    }

    /**
     * @return list<array{name: string, commit: string}>
     */
    private function giteaTags(Project $project): array
    {
        if (parse_url($project->gitea_url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('La consulta de tags por API requiere HTTPS o una URL SSH.');
        }

        $url = $project->gitea_url.'/api/v1/repos/'
            .rawurlencode($project->owner).'/'
            .rawurlencode($project->repository).'/tags';
        $tags = [];

        for ($page = 1; $page <= self::MAX_PAGES + 1; $page++) {
            $request = Http::connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->acceptJson();

            if ($project->gitea_token) {
                $request = $request->withHeaders(['Authorization' => 'token '.$project->gitea_token]);
            }

            $response = $request->get($url, ['limit' => self::PAGE_SIZE, 'page' => $page])->throw();
            $pageTags = json_decode($response->body());

            if (! is_array($pageTags)) {
                throw new RuntimeException('Gitea devolvió una lista de tags inválida.');
            }

            foreach ($pageTags as $tag) {
                if (! is_object($tag)) {
                    throw new RuntimeException('Gitea devolvió un tag inválido.');
                }

                $name = $tag->name ?? null;
                $commitData = $tag->commit ?? null;
                $commit = is_object($commitData) ? ($commitData->sha ?? null) : null;

                if (! is_string($name) || ! is_string($commit) || ! preg_match('/\A[a-f0-9]{40}\z/iD', $commit)) {
                    throw new RuntimeException('Gitea devolvió un tag sin un commit válido.');
                }

                $tags[] = ['name' => $name, 'commit' => strtolower($commit)];
            }

            if (count($pageTags) < self::PAGE_SIZE) {
                return $tags;
            }

            if ($page > self::MAX_PAGES) {
                throw new RuntimeException('El repositorio contiene demasiados tags para una sincronización segura.');
            }
        }

        throw new RuntimeException('No se pudo completar la consulta paginada de tags.');
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
