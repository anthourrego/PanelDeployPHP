<?php

namespace App\Services;

use Dotenv\Dotenv;
use Dotenv\Parser\Lines;

class InstallerEnvironment
{
    /**
     * @param  array{driver?: string, host: string, port: string, database: string, username: string, password: string, trust_certificate: bool}  $connection
     */
    public function write(array $connection, string $appUrl): void
    {
        $this->writeValues([
            'APP_URL' => $appUrl,
            'DB_CONNECTION' => $connection['driver'] ?? 'sqlsrv',
            'DB_URL' => '',
            'DB_SOCKET' => '',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'],
            'DB_ENCRYPT' => 'yes',
            'DB_TRUST_SERVER_CERTIFICATE' => $connection['trust_certificate'] ? 'true' : 'false',
        ]);
    }

    public function writeAppUrl(string $appUrl): void
    {
        $this->writeValues(['APP_URL' => $appUrl]);
    }

    /** @param array<string, string> $values */
    private function writeValues(array $values): void
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || is_link($path) || ! is_writable(dirname($path))) {
            throw new InstallerException('No se puede actualizar el archivo de entorno de forma segura.');
        }

        $original = file_get_contents($path);
        if ($original === false) {
            throw new InstallerException('No se pudo leer el archivo de entorno.');
        }

        $entries = Lines::process(explode("\n", $original));
        $seen = [];
        foreach ($entries as $entry) {
            if (str_contains($entry, "\n")
                && preg_match('/(?:^|\n)[ \t]*(?:export[ \t]+)?(?:'.implode('|', array_keys($values)).')[ \t]*=/D', $entry)) {
                throw new InstallerException('El archivo de entorno contiene variables de configuración multilínea.');
            }
            if (preg_match('/^[ \t]*(?:export[ \t]+)?([A-Za-z0-9_]+)[ \t]*=/', $entry, $matches) && array_key_exists($matches[1], $values)) {
                if (isset($seen[$matches[1]]) || str_contains($entry, "\n")) {
                    throw new InstallerException('El archivo de entorno contiene variables de configuración duplicadas o multilínea.');
                }
                $seen[$matches[1]] = true;
            }
        }

        $updated = $original;
        foreach ($values as $key => $value) {
            $encoded = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
            $line = $key.'='.$encoded;
            if (isset($seen[$key])) {
                $updated = preg_replace_callback('/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m', fn (): string => $line, $updated, 1);
            } else {
                $updated = rtrim($updated, "\r\n")."\n".$line."\n";
            }
        }

        $parsed = Dotenv::parse($updated);
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $parsed) || $parsed[$key] !== $value) {
                throw new InstallerException('No se pudo verificar el archivo de entorno actualizado.');
            }
        }

        if (file_get_contents($path) !== $original) {
            throw new InstallerException('El archivo de entorno cambió durante la instalación. Inténtelo nuevamente.');
        }

        $temporary = tempnam(dirname($path), '.panel-install-');
        if ($temporary === false) {
            throw new InstallerException('No se pudo crear un archivo temporal seguro.');
        }
        try {
            if (! chmod($temporary, 0600) || file_put_contents($temporary, $updated) !== strlen($updated) || ! rename($temporary, $path)) {
                throw new InstallerException('No se pudo guardar la configuración de instalación.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
