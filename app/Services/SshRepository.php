<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class SshRepository
{
    public static function validUrl(string $url, string $owner, string $repository): bool
    {
        $host = '(?:[A-Za-z0-9_][A-Za-z0-9._-]*|\[[0-9A-Fa-f:]+\])';
        $user = '[A-Za-z0-9_][A-Za-z0-9._-]*';
        if (! preg_match('~\A(?:ssh://'.$user.'@'.$host.'(?::([0-9]{1,5}))?/|'.$user.'@'.$host.':)([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)\.git\z~D', $url, $parts)) {
            return false;
        }

        return ($parts[1] === '' || ((int) $parts[1] >= 1 && (int) $parts[1] <= 65535))
            && ! in_array($owner, ['.', '..'], true)
            && ! in_array($repository, ['.', '..'], true)
            && $parts[2] === $owner && $parts[3] === $repository;
    }

    public function resolve(string $url, string $reference): string
    {
        if (! preg_match('~\A(?:refs/(?:heads|tags)/[^\x00-\x20\x7f]+|[A-Za-z0-9_][A-Za-z0-9._/-]*)\z~D', $reference)) {
            throw new RuntimeException('La referencia no es válida.');
        }
        $scratch = storage_path('app/private/git-'.Str::uuid());
        if (! File::makeDirectory($scratch, 0700, true)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de Git.');
        }
        try {
            $this->git(['init', '--bare', '--quiet', $scratch], $scratch);
            if (preg_match('/\A[a-fA-F0-9]{40}\z/D', $reference)) {
                $requested = strtolower($reference);
                $expected = $requested;
            } else {
                $candidates = str_starts_with($reference, 'refs/heads/') || str_starts_with($reference, 'refs/tags/')
                    ? [$reference]
                    : ['refs/heads/'.$reference, 'refs/tags/'.$reference];
                foreach ($candidates as $candidate) {
                    $this->git(['check-ref-format', $candidate], $scratch);
                }
                $patterns = $candidates;
                foreach ($candidates as $candidate) {
                    if (str_starts_with($candidate, 'refs/tags/')) {
                        $patterns[] = $candidate.'^{}';
                    }
                }
                $advertisement = $this->git(array_merge(['ls-remote', '--heads', '--tags', '--', $url], $patterns), $scratch);
                $matches = [];
                $peeled = [];
                foreach (preg_split('/\R/', trim($advertisement)) as $line) {
                    if (preg_match('/\A([a-f0-9]{40})\t(refs\/(?:heads|tags)\/[^\s]+)\z/iD', $line, $match)) {
                        if (str_ends_with($match[2], '^{}')) {
                            $peeled[substr($match[2], 0, -3)] = strtolower($match[1]);
                        } elseif (in_array($match[2], $candidates, true)) {
                            $matches[$match[2]] = strtolower($match[1]);
                        }
                    }
                }
                if (count($matches) !== 1) {
                    throw new RuntimeException('La referencia no existe o coincide con una rama y una etiqueta; use refs/heads/... o refs/tags/....');
                }
                $requested = array_key_first($matches);
                $expected = $peeled[$requested] ?? $matches[$requested];
            }
            if ($requested === $expected) {
                $this->git(['-c', 'protocol.file.allow=never', 'fetch', '--no-tags', '--depth=1', '--no-recurse-submodules', '--', $url, $requested], $scratch);
                $sha = trim($this->git(['rev-parse', '--verify', 'FETCH_HEAD^{commit}'], $scratch));
            } else {
                $sha = $expected;
            }
            if (! preg_match('/\A[a-f0-9]{40}\z/D', $sha) || ! hash_equals($expected, $sha)) {
                throw new RuntimeException('La referencia cambió durante la consulta o no apunta a un commit.');
            }

            return $sha;
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * @return 'behind'|'ahead'|'diverged'
     */
    public function compareCommits(string $url, string $owner, string $repository, string $deployedCommit, string $availableCommit): string
    {
        if (! self::validUrl($url, $owner, $repository)) {
            throw new RuntimeException('La URL SSH no corresponde al repositorio configurado.');
        }

        if (! preg_match('/\A[a-f0-9]{40}\z/iD', $deployedCommit) || ! preg_match('/\A[a-f0-9]{40}\z/iD', $availableCommit)) {
            throw new RuntimeException('Los commits no tienen un formato válido.');
        }

        $deployedCommit = strtolower($deployedCommit);
        $availableCommit = strtolower($availableCommit);
        $scratch = storage_path('app/private/git-'.Str::uuid());
        if (! File::makeDirectory($scratch, 0700, true)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de Git.');
        }

        try {
            $this->git(['init', '--bare', '--quiet', $scratch], $scratch);
            $this->git(['-c', 'protocol.file.allow=never', 'fetch', '--no-tags', '--no-recurse-submodules', '--filter=blob:none', '--', $url, $deployedCommit, $availableCommit], $scratch, 30);
            $this->git(['cat-file', '-e', $deployedCommit.'^{commit}'], $scratch);
            $this->git(['cat-file', '-e', $availableCommit.'^{commit}'], $scratch);
            $mergeBases = preg_split('/\R/', trim($this->git(['merge-base', '--all', $deployedCommit, $availableCommit], $scratch, 10, true)));

            if (in_array($deployedCommit, $mergeBases, true)) {
                return 'behind';
            }

            if (in_array($availableCommit, $mergeBases, true)) {
                return 'ahead';
            }

            return 'diverged';
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    /**
     * @return list<array{name: string, commit: string}>
     */
    public function listTags(string $url, string $owner, string $repository): array
    {
        return $this->listReferences($url, $owner, $repository, 'tags');
    }

    /**
     * @return list<array{name: string, commit: string}>
     */
    public function listBranches(string $url, string $owner, string $repository): array
    {
        return $this->listReferences($url, $owner, $repository, 'heads');
    }

    /**
     * @param  'heads'|'tags'  $namespace
     * @return list<array{name: string, commit: string}>
     */
    private function listReferences(string $url, string $owner, string $repository, string $namespace): array
    {
        if (! self::validUrl($url, $owner, $repository)) {
            throw new RuntimeException('La URL SSH no corresponde al repositorio configurado.');
        }

        $scratch = storage_path('app/private/git-'.Str::uuid());
        if (! File::makeDirectory($scratch, 0700, true)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de Git.');
        }

        try {
            $this->git(['init', '--bare', '--quiet', $scratch], $scratch);
            $advertisement = $this->git(['ls-remote', '--'.$namespace, '--', $url], $scratch, 15);
            $objects = [];
            $peeledTags = [];

            foreach (preg_split('/\R/', trim($advertisement)) as $line) {
                if ($line === '') {
                    continue;
                }

                if (! preg_match('~\A([a-f0-9]{40})\trefs/'.$namespace.'/(.+)\z~iD', $line, $match)) {
                    throw new RuntimeException('Git devolvió una lista de referencias inválida.');
                }

                $reference = $match[2];
                $tagName = str_ends_with($reference, '^{}')
                    ? substr($reference, 0, -3)
                    : $reference;
                $sha = strtolower($match[1]);

                if (str_ends_with($reference, '^{}')) {
                    $peeledTags[$tagName] = $sha;
                } else {
                    $objects[$tagName] = $sha;
                }
            }

            $references = [];
            foreach ($objects as $name => $sha) {
                $references[] = [
                    'name' => (string) $name,
                    'commit' => $peeledTags[$name] ?? $sha,
                ];
            }

            return $references;
        } finally {
            File::deleteDirectory($scratch);
        }
    }

    private function git(array $arguments, string $path, int $timeout = 120, bool $allowNoMergeBase = false): string
    {
        $result = Process::path($path)->timeout($timeout)->env([
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes',
            'GIT_SSH_VARIANT' => 'ssh',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
            'GIT_CONFIG_COUNT' => '0',
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
        ])->run(array_merge(['git'], $arguments));
        if (! $result->successful() && ! ($allowNoMergeBase && $result->exitCode() === 1)) {
            throw new RuntimeException('Falló la consulta Git por SSH; compruebe la clave, la huella del servidor y la referencia.');
        }

        return $result->output();
    }
}
