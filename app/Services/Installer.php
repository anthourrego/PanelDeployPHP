<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class Installer
{
    public function __construct(private InstallerEnvironment $environment) {}

    public function enable(): string
    {
        if (app()->configurationIsCached() || config('app.debug') || ! config('app.key')) {
            throw new InstallerException('Configure APP_KEY y APP_DEBUG=false, y elimine la caché de configuración antes de habilitar la instalación.');
        }

        return $this->locked(function (?array $state): string {
            if (($state['phase'] ?? null) === 'complete') {
                throw new InstallerException('La instalación ya fue completada.');
            }

            if ($state === null || ($state['phase'] ?? null) === 'connection') {
                if (config('database.connections.'.config('database.default').'.driver') !== 'sqlite') {
                    throw new InstallerException('La instalación inicial requiere una base SQLite temporal vacía.');
                }
                try {
                    $database = DB::connection();
                    $schema = $database->getSchemaBuilder();
                    $hasObjects = $schema->getTables() !== [] || $schema->getViews() !== [];
                } catch (Throwable $exception) {
                    Log::error('No se pudo comprobar la base inicial.', ['exception' => $exception::class]);
                    throw new InstallerException('No se pudo comprobar la base inicial. Configure una base vacía accesible antes de activar el asistente.');
                }
                if ($hasObjects) {
                    throw new InstallerException('La conexión actual contiene tablas; el asistente solo permite instalaciones nuevas.');
                }
            }

            $code = bin2hex(random_bytes(32));
            $state ??= ['phase' => 'connection'];
            $state['code_hash'] = hash('sha256', $code);
            $state['expires_at'] = now()->addMinutes(30)->timestamp;
            $state['attempts'] = 0;
            $state['claimed'] = false;
            $this->writeState($state);

            return $code;
        });
    }

    public function isEnabled(): bool
    {
        if (! is_file($this->statePath())) {
            return false;
        }

        return $this->locked(fn (?array $state): bool => $this->active($state));
    }

    public function isComplete(): bool
    {
        if (! is_file($this->statePath())) {
            return false;
        }

        return $this->locked(fn (?array $state): bool => ($state['phase'] ?? null) === 'complete');
    }

    public function authorized(?string $authorization): bool
    {
        return $this->locked(fn (?array $state): bool => $this->active($state)
            && is_string($authorization)
            && hash_equals($state['code_hash'], $authorization)
            && ($state['claimed'] ?? false) === true
            && in_array($state['phase'], ['prepared', 'migrating'], true));
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string, trust_certificate: bool}  $connection
     */
    public function prepare(string $code, array $connection): string
    {
        return $this->locked(function (?array $state) use ($code, $connection): string {
            if (! $this->active($state)) {
                throw new InstallerException('La instalación no está habilitada o el código venció.');
            }

            if (! hash_equals($state['code_hash'], hash('sha256', $code))) {
                $state['attempts']++;
                $this->writeState($state);
                throw new InstallerException('Código de instalación incorrecto.');
            }

            if (($state['claimed'] ?? false) === true) {
                throw new InstallerException('El código ya se utilizó. Genere otro desde el servidor para continuar.');
            }

            $fingerprint = $this->fingerprint($connection);
            if ($state['phase'] !== 'connection') {
                if (! hash_equals($state['fingerprint'], $fingerprint)) {
                    throw new InstallerException('La conexión no corresponde a la instalación iniciada.');
                }

                $state['claimed'] = true;
                $this->writeState($state);

                return $state['code_hash'];
            }

            try {
                config(['database.connections.installer' => $this->connectionConfig($connection)]);
                DB::purge('installer');
                $database = DB::connection('installer');
                $this->checkTarget($database, $connection['database']);
            } catch (Throwable $exception) {
                Log::warning('Falló la comprobación de la base de instalación.', ['exception' => $exception::class]);
                throw new InstallerException('No se pudo validar la conexión o la base contiene tablas o vistas. Verifique la conexión, el certificado y los permisos.');
            } finally {
                DB::purge('installer');
            }

            $this->environment->write($connection);
            $state['fingerprint'] = $fingerprint;
            $state['phase'] = 'prepared';
            $state['claimed'] = true;
            $this->writeState($state);

            return $state['code_hash'];
        });
    }

    /**
     * @param  array{name: string, username: string, email: string, password: string}  $admin
     */
    public function finish(string $authorization, array $admin): void
    {
        $this->locked(function (?array $state) use ($authorization, $admin): void {
            if (! $this->active($state) || ! hash_equals($state['code_hash'], $authorization)
                || ($state['claimed'] ?? false) !== true
                || ! in_array($state['phase'], ['prepared', 'migrating'], true)) {
                throw new InstallerException('La instalación no está autorizada o el código venció.');
            }

            $connection = $this->configuredConnection();
            if (! hash_equals($state['fingerprint'], $this->fingerprint($connection))) {
                throw new InstallerException('La configuración activa no coincide con la conexión guardada. Revise las variables externas y la caché de configuración.');
            }

            try {
                $database = DB::connection('sqlsrv');
                if ($state['phase'] === 'prepared') {
                    $this->checkTarget($database, $connection['database']);
                    $state['phase'] = 'migrating';
                    $this->writeState($state);
                } elseif ($database->getSchemaBuilder()->hasTable('users') && $database->table('users')->exists()) {
                    throw new InstallerException('Ya existe un usuario. Revise la instalación manualmente antes de continuar.');
                }

                if (Artisan::call('migrate', ['--database' => 'sqlsrv', '--force' => true]) !== 0) {
                    throw new InstallerException('Las migraciones no finalizaron.');
                }

                $database->transaction(function () use ($admin): void {
                    if (User::query()->exists()) {
                        throw new InstallerException('Ya existe un usuario. Revise la instalación manualmente antes de continuar.');
                    }

                    $user = User::create($admin);
                    $user->forceFill(['is_admin' => true])->save();
                });
            } catch (Throwable $exception) {
                Log::error('Falló la inicialización de la base.', ['exception' => $exception::class]);
                throw new InstallerException('No se completó la instalación. No se borraron datos; revise las migraciones y los permisos antes de reintentar.');
            }

            $state['phase'] = 'complete';
            unset($state['code_hash'], $state['fingerprint']);
            $this->writeState($state);
        });
    }

    private function active(?array $state): bool
    {
        return $state !== null
            && in_array($state['phase'] ?? null, ['connection', 'prepared', 'migrating'], true)
            && is_string($state['code_hash'] ?? null)
            && is_int($state['expires_at'] ?? null)
            && ($state['attempts'] ?? 10) < 10
            && $state['expires_at'] > now()->timestamp;
    }

    /** @param array{host: string, port: string, database: string, username: string, password: string, trust_certificate: bool} $connection */
    private function connectionConfig(array $connection): array
    {
        return [
            'driver' => 'sqlsrv',
            'host' => $connection['host'],
            'port' => $connection['port'],
            'database' => $connection['database'],
            'username' => $connection['username'],
            'password' => $connection['password'],
            'prefix' => '',
            'prefix_indexes' => true,
            'charset' => 'utf8',
            'encrypt' => 'yes',
            'trust_server_certificate' => $connection['trust_certificate'] ? 'true' : 'false',
            'login_timeout' => 10,
        ];
    }

    private function checkTarget(Connection $connection, string $database): void
    {
        $result = $connection->selectOne("SELECT DB_NAME() AS database_name, HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'VIEW DEFINITION') AS can_view");
        if (! $result || strcasecmp($result->database_name, $database) !== 0 || (int) $result->can_view !== 1) {
            throw new InstallerException('No se puede comprobar la base de destino.');
        }

        $objects = $connection->selectOne("SELECT COUNT(*) AS object_count FROM sys.objects WHERE is_ms_shipped = 0 AND type IN ('U', 'V')");
        if (! $objects || (int) $objects->object_count !== 0) {
            throw new InstallerException('La base contiene tablas o vistas.');
        }
    }

    private function configuredConnection(): array
    {
        if (app()->configurationIsCached() || config('database.default') !== 'sqlsrv'
            || config('database.connections.sqlsrv.url')
            || config('database.connections.sqlsrv.encrypt') !== 'yes') {
            throw new InstallerException('La configuración activa no corresponde a la instalación.');
        }

        $config = config('database.connections.sqlsrv');

        return [
            'host' => (string) $config['host'],
            'port' => (string) $config['port'],
            'database' => (string) $config['database'],
            'username' => (string) $config['username'],
            'password' => (string) $config['password'],
            'trust_certificate' => filter_var($config['trust_server_certificate'], FILTER_VALIDATE_BOOL),
        ];
    }

    private function fingerprint(array $connection): string
    {
        return hash_hmac('sha256', json_encode($connection, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function statePath(): string
    {
        return storage_path('app/private/panel-installer.json');
    }

    private function locked(callable $callback): mixed
    {
        $lock = fopen(storage_path('app/private/panel-installer.lock'), 'c');
        if ($lock === false || ! chmod(storage_path('app/private/panel-installer.lock'), 0600) || ! flock($lock, LOCK_EX)) {
            throw new InstallerException('No se pudo bloquear el asistente de instalación.');
        }

        try {
            $path = $this->statePath();
            $state = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
            if ($state !== null && ! is_array($state)) {
                throw new InstallerException('El estado de instalación es inválido.');
            }

            return $callback($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeState(array $state): void
    {
        $this->atomicWrite($this->statePath(), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam(dirname($path), '.panel-install-');
        if ($temporary === false) {
            throw new InstallerException('No se pudo crear un archivo temporal seguro.');
        }

        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $contents) !== strlen($contents) || ! rename($temporary, $path)) {
                throw new InstallerException('No se pudo guardar la configuración de instalación.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
