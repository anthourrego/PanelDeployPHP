<?php

namespace Tests\Feature;

use App\Services\Installer;
use App\Services\InstallerException;
use App\Services\LocalInstallerBootstrap;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class LocalInstallerBootstrapTest extends TestCase
{
    private string $temporaryDirectory;

    private array $environmentVariables = [];

    protected function setUp(): void
    {
        parent::setUp();

        $template = file_get_contents(base_path('.env.example'));
        $this->temporaryDirectory = sys_get_temp_dir().'/panel-local-test-'.bin2hex(random_bytes(12));
        mkdir($this->temporaryDirectory.'/storage/app/private', 0700, true);
        mkdir($this->temporaryDirectory.'/database', 0700, true);
        mkdir($this->temporaryDirectory.'/public', 0700, true);
        file_put_contents($this->temporaryDirectory.'/.env.example', $template);
        $this->app->setBasePath($this->temporaryDirectory);
        $this->app->useEnvironmentPath($this->temporaryDirectory);
        $this->app->useDatabasePath($this->temporaryDirectory.'/database');
        $this->app->useStoragePath($this->temporaryDirectory.'/storage');
        $this->app->usePublicPath($this->temporaryDirectory.'/public');
        config(['app.key' => null, 'database.default' => 'sqlite']);

        foreach ([
            'APP_ENV', 'APP_KEY', 'APP_PREVIOUS_KEYS', 'APP_DEBUG', 'APP_URL',
            'DB_CONNECTION', 'DB_URL', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
            'DB_ENCRYPT', 'DB_TRUST_SERVER_CERTIFICATE', 'SESSION_DRIVER', 'SESSION_CONNECTION',
            'SESSION_DOMAIN', 'SESSION_PATH', 'SESSION_SECURE_COOKIE', 'PANEL_INSTALLER_LOCAL', 'PHP_CLI_SERVER_WORKERS',
        ] as $name) {
            $this->environmentVariables[$name] = [
                'process' => getenv($name),
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
                'env_exists' => array_key_exists($name, $_ENV),
                'server_exists' => array_key_exists($name, $_SERVER),
            ];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentVariables as $name => $values) {
            $values['process'] === false ? putenv($name) : putenv($name.'='.$values['process']);
            if ($values['env_exists']) {
                $_ENV[$name] = $values['env'];
            }
            if ($values['server_exists']) {
                $_SERVER[$name] = $values['server'];
            }
        }
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_new_installation_creates_configuration_and_activates_installer(): void
    {
        if (! extension_loaded('pdo_sqlite') || ! extension_loaded('pdo_sqlsrv')) {
            $this->markTestSkipped('La instalación local requiere pdo_sqlite y pdo_sqlsrv.');
        }
        Process::fake();

        $code = app(LocalInstallerBootstrap::class)->prepare();

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $code);
        $environment = Dotenv::parse(file_get_contents($this->temporaryDirectory.'/.env'));
        $this->assertStringStartsWith('base64:', $environment['APP_KEY']);
        $this->assertSame(32, strlen(base64_decode(substr($environment['APP_KEY'], 7), true)));
        $this->assertSame('false', $environment['APP_DEBUG']);
        $this->assertSame('http://127.0.0.1:8000', $environment['APP_URL']);
        $this->assertFileExists($this->temporaryDirectory.'/database/database.sqlite');
        $this->assertTrue(app(Installer::class)->isEnabled());
        Process::assertRan(['npm', 'ci', '--ignore-scripts']);
        Process::assertRan(['npm', 'run', 'build']);
    }

    public function test_existing_environment_is_not_overwritten_or_activated(): void
    {
        Process::fake();
        $path = $this->temporaryDirectory.'/.env';
        file_put_contents($path, "APP_KEY=existing-key\nDB_CONNECTION=sqlsrv\n");

        $result = app(LocalInstallerBootstrap::class)->prepare();

        $this->assertNull($result);
        $this->assertSame("APP_KEY=existing-key\nDB_CONNECTION=sqlsrv\n", file_get_contents($path));
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/storage/app/private/panel-installer.json');
        Process::assertNothingRan();
    }

    public function test_existing_sqlite_database_prevents_automatic_setup(): void
    {
        Process::fake();
        $path = $this->temporaryDirectory.'/database/database.sqlite';
        file_put_contents($path, 'existing-data');

        try {
            app(LocalInstallerBootstrap::class)->prepare();
            $this->fail('Se esperaba el rechazo de la base existente.');
        } catch (InstallerException $exception) {
            $this->assertStringContainsString('datos existentes', $exception->getMessage());
        }

        $this->assertSame('existing-data', file_get_contents($path));
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/.env');
        Process::assertNothingRan();
    }

    public function test_existing_storage_data_prevents_automatic_setup(): void
    {
        Process::fake();
        $path = $this->temporaryDirectory.'/storage/app/private/existing-data';
        file_put_contents($path, 'existing-data');

        try {
            app(LocalInstallerBootstrap::class)->prepare();
            $this->fail('Se esperaba el rechazo de los datos existentes.');
        } catch (InstallerException $exception) {
            $this->assertStringContainsString('instalación anterior', $exception->getMessage());
        }

        $this->assertSame('existing-data', file_get_contents($path));
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/.env');
        Process::assertNothingRan();
    }

    public function test_external_database_configuration_prevents_automatic_setup(): void
    {
        Process::fake();
        putenv('DB_CONNECTION=sqlsrv');

        try {
            app(LocalInstallerBootstrap::class)->prepare();
            $this->fail('Se esperaba el rechazo de la configuración externa.');
        } catch (InstallerException $exception) {
            $this->assertStringContainsString('variables externas', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->temporaryDirectory.'/.env');
        Process::assertNothingRan();
    }

    public function test_completed_installation_is_not_reactivated(): void
    {
        Process::fake();
        file_put_contents($this->temporaryDirectory.'/.env', "APP_KEY=existing-key\n");
        file_put_contents($this->temporaryDirectory.'/storage/app/private/panel-local-setup', "local\n");
        file_put_contents($this->temporaryDirectory.'/storage/app/private/panel-installer.json', '{"phase":"complete"}');

        $result = app(LocalInstallerBootstrap::class)->prepare();

        $this->assertNull($result);
        $this->assertSame("APP_KEY=existing-key\n", file_get_contents($this->temporaryDirectory.'/.env'));
        Process::assertNothingRan();
    }

    public function test_missing_environment_after_installation_never_generates_a_new_key(): void
    {
        Process::fake();
        file_put_contents($this->temporaryDirectory.'/storage/app/private/panel-local-setup', "local\n");
        file_put_contents($this->temporaryDirectory.'/storage/app/private/panel-installer.json', '{"phase":"complete"}');

        try {
            app(LocalInstallerBootstrap::class)->prepare();
            $this->fail('Se esperaba el rechazo de la instalación sin entorno.');
        } catch (InstallerException $exception) {
            $this->assertStringContainsString('no se generó una nueva clave', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->temporaryDirectory.'/.env');
        Process::assertNothingRan();
    }

    public function test_failed_frontend_build_can_be_retried_without_replacing_a_key(): void
    {
        if (! extension_loaded('pdo_sqlite') || ! extension_loaded('pdo_sqlsrv')) {
            $this->markTestSkipped('La instalación local requiere pdo_sqlite y pdo_sqlsrv.');
        }
        Process::fake(['*' => Process::result(exitCode: 1)]);

        try {
            app(LocalInstallerBootstrap::class)->prepare();
            $this->fail('Se esperaba el fallo de las dependencias.');
        } catch (InstallerException $exception) {
            $this->assertStringContainsString('dependencias del frontend', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->temporaryDirectory.'/.env');

        Process::fake();
        $bootstrap = app(LocalInstallerBootstrap::class);
        $firstCode = $bootstrap->prepare();
        $original = file_get_contents($this->temporaryDirectory.'/.env');
        $secondCode = $bootstrap->prepare();

        $this->assertNotSame($firstCode, $secondCode);
        $this->assertSame($original, file_get_contents($this->temporaryDirectory.'/.env'));
    }

    public function test_local_http_mode_is_rejected_outside_the_local_php_server(): void
    {
        config(['app.installer_local' => true]);

        $this->get('http://127.0.0.1:8000/instalacion')->assertNotFound();
    }
}
