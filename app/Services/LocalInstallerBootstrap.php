<?php

namespace App\Services;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class LocalInstallerBootstrap
{
    public function __construct(private Installer $installer) {}

    public function prepare(): ?string
    {
        $marker = storage_path('app/private/panel-local-setup');
        $environment = app()->environmentFilePath();

        if (is_link($marker)) {
            throw new InstallerException('El marcador local no es un archivo seguro.');
        }
        if (is_file($environment) && ! is_file($marker)) {
            return null;
        }
        if (is_file($marker) && $this->installer->isComplete()) {
            if (! is_file($environment)) {
                throw new InstallerException('Falta .env de una instalación existente; no se generó una nueva clave.');
            }

            return null;
        }

        if (! is_file($marker)) {
            $this->assertFreshInstallation();
        }

        if (! extension_loaded('pdo_sqlite') || ! extension_loaded('pdo_sqlsrv')) {
            throw new InstallerException('La instalación requiere las extensiones PHP pdo_sqlite y pdo_sqlsrv.');
        }

        if (! is_file($marker)) {
            $this->createFile($marker, "local\n");
        }

        if (! is_file($environment)) {
            if (is_file(database_path('database.sqlite')) || is_file(storage_path('app/private/panel-installer.json'))) {
                throw new InstallerException('Falta .env pero hay datos de una instalación previa. No se generó una clave.');
            }
        }

        $this->buildFrontend();

        if (! is_file($environment)) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $template = file_get_contents(base_path('.env.example'));
            if ($template === false) {
                throw new InstallerException('No se pudo leer .env.example.');
            }

            $contents = $template;
            foreach (['APP_KEY' => $key, 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8000'] as $name => $value) {
                $contents = preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $name.'='.$value, $contents, 1, $count);
                if ($count !== 1) {
                    throw new InstallerException('La plantilla de entorno no contiene la configuración inicial esperada.');
                }
            }

            $parsed = Dotenv::parse($contents);
            if (($parsed['APP_KEY'] ?? null) !== $key || ($parsed['APP_DEBUG'] ?? null) !== 'false'
                || ($parsed['APP_URL'] ?? null) !== 'http://127.0.0.1:8000'
                || ($parsed['DB_CONNECTION'] ?? null) !== 'sqlite'
                || ! empty($parsed['DB_DATABASE']) || ! empty($parsed['DB_URL'])) {
                throw new InstallerException('La plantilla de entorno no corresponde a una instalación nueva.');
            }
            $this->createFile($environment, $contents);

            config([
                'app.key' => $key,
                'app.debug' => false,
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => database_path('database.sqlite'),
                'database.connections.sqlite.url' => null,
            ]);
            DB::purge('sqlite');
        }

        if (! is_file(database_path('database.sqlite'))) {
            $this->createFile(database_path('database.sqlite'), '');
        }

        return $this->installer->enable();
    }

    private function assertFreshInstallation(): void
    {
        if (app()->configurationIsCached() || config('app.key')
            || is_file(database_path('database.sqlite'))
            || is_file(public_path('hot'))
            || is_dir(public_path('build'))) {
            throw new InstallerException('Se encontró configuración o datos existentes; no se iniciará la instalación automática.');
        }

        foreach ([
            'APP_ENV', 'APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_DEBUG', 'APP_URL',
            'DB_CONNECTION', 'DB_URL', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_ENCRYPT', 'DB_TRUST_SERVER_CERTIFICATE', 'SESSION_DRIVER', 'SESSION_CONNECTION',
            'SESSION_DOMAIN', 'SESSION_PATH', 'SESSION_SECURE_COOKIE', 'PANEL_INSTALLER_LOCAL', 'PHP_CLI_SERVER_WORKERS',
        ] as $name) {
            if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                throw new InstallerException('Existen variables externas de aplicación o base de datos; no se iniciará la instalación automática.');
            }
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(storage_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isLink() || (! $file->isDir() && $file->getFilename() !== '.gitignore')) {
                throw new InstallerException('Se encontraron archivos de una instalación anterior; no se iniciará la instalación automática.');
            }
        }
    }

    private function buildFrontend(): void
    {
        if (! is_dir(base_path('node_modules'))) {
            $install = Process::path(base_path())->timeout(600)->run(['npm', 'ci', '--ignore-scripts']);
            if (! $install->successful()) {
                throw new InstallerException('No se pudieron instalar las dependencias del frontend. Revise Node.js, npm y la conectividad.');
            }
        }

        $build = Process::path(base_path())->timeout(600)->run(['npm', 'run', 'build']);
        if (! $build->successful()) {
            throw new InstallerException('No se pudo compilar el frontend. Revise la instalación de Node.js y npm.');
        }
    }

    private function createFile(string $path, string $contents): void
    {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new InstallerException('No se pudo crear un archivo de instalación sin sobrescribir datos.');
        }

        try {
            if (! chmod($path, 0600) || fwrite($file, $contents) !== strlen($contents) || ! fflush($file)) {
                throw new InstallerException('No se pudo guardar un archivo de instalación.');
            }
        } finally {
            fclose($file);
        }
    }
}
