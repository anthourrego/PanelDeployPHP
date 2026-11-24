<?php

namespace Tests\Feature;

use App\Services\Installer;
use App\Services\InstallerEnvironment;
use App\Services\InstallerException;
use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/panel-installer-test-'.bin2hex(random_bytes(12));
        mkdir($this->temporaryDirectory.'/app/private', 0700, true);
        mkdir($this->temporaryDirectory.'/sessions', 0700, true);
        file_put_contents($this->temporaryDirectory.'/.env', "APP_KEY=test-preserved-key\nDB_CONNECTION=sqlite\n");
        $this->app->useStoragePath($this->temporaryDirectory);
        $this->app->useEnvironmentPath($this->temporaryDirectory);
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'session.files' => $this->temporaryDirectory.'/sessions',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_installer_is_unavailable_without_activation_or_https(): void
    {
        $this->get('https://localhost/instalacion')->assertNotFound();

        app(Installer::class)->enable();

        $this->get('http://localhost/instalacion')->assertNotFound();
        $this->get('https://localhost/instalacion')->assertOk()->assertSee('Código temporal');
        $this->post('https://localhost/instalacion/finalizar')->assertForbidden();
    }

    public function test_activation_refuses_a_database_with_existing_tables(): void
    {
        Schema::create('existing_data', fn ($table) => $table->id());

        $this->artisan('panel:install-enable')->assertFailed()
            ->expectsOutput('La conexión actual contiene tablas; el asistente solo permite instalaciones nuevas.');

        $this->assertFileDoesNotExist($this->temporaryDirectory.'/app/private/panel-installer.json');
    }

    public function test_activation_refuses_an_existing_sql_server_configuration(): void
    {
        config(['database.default' => 'sqlsrv']);

        $this->expectException(InstallerException::class);
        $this->expectExceptionMessage('La instalación inicial requiere una base SQLite temporal vacía.');
        app(Installer::class)->enable();
    }

    public function test_incorrect_codes_are_limited_and_never_appear_in_the_response(): void
    {
        app(Installer::class)->enable();
        $input = [
            'code' => str_repeat('a', 64),
            'host' => 'localhost',
            'port' => '1433',
            'database' => 'test',
            'username' => 'test',
            'db_password' => 'secret-for-test',
        ];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('https://localhost/instalacion/conexion', $input)
                ->assertUnprocessable()
                ->assertSee('Código de instalación incorrecto.')
                ->assertDontSee('secret-for-test');
        }

        $this->get('https://localhost/instalacion')->assertNotFound();
    }

    public function test_installer_rejects_a_request_without_a_csrf_token(): void
    {
        app(Installer::class)->enable();
        $this->app->instance('env', 'production');

        $this->post('https://localhost/instalacion/conexion', [])->assertStatus(419);
    }

    public function test_failed_connection_does_not_save_or_expose_credentials(): void
    {
        $code = app(Installer::class)->enable();
        $path = $this->temporaryDirectory.'/.env';
        $original = file_get_contents($path);
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('connection')->with('installer')->once()->andThrow(new PDOException('sensitive database failure'));
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('https://localhost/instalacion/conexion', [
            'code' => $code,
            'host' => 'example.test',
            'port' => '1433',
            'database' => 'test',
            'username' => 'test',
            'db_password' => 'secret-for-test',
        ])->assertUnprocessable()
            ->assertSee('No se pudo validar la conexión')
            ->assertDontSee('secret-for-test')
            ->assertDontSee('sensitive database failure');

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_a_code_can_be_claimed_once_and_renewal_revokes_the_previous_session(): void
    {
        $installer = app(Installer::class);
        $code = $installer->enable();
        $connection = [
            'host' => 'example.test',
            'port' => '1433',
            'database' => 'test',
            'username' => 'test',
            'password' => 'test',
            'trust_certificate' => false,
        ];
        $database = $this->mock(Connection::class);
        $database->shouldReceive('selectOne')->once()
            ->with("SELECT DB_NAME() AS database_name, HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'VIEW DEFINITION') AS can_view")
            ->andReturn((object) ['database_name' => 'test', 'can_view' => 1]);
        $database->shouldReceive('selectOne')->once()
            ->with("SELECT COUNT(*) AS object_count FROM sys.objects WHERE is_ms_shipped = 0 AND type IN ('U', 'V')")
            ->andReturn((object) ['object_count' => 0]);
        DB::shouldReceive('connection')->with('installer')->once()->andReturn($database);
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $authorization = $installer->prepare($code, $connection);
        $this->assertTrue($installer->authorized($authorization));
        try {
            $installer->prepare($code, $connection);
            $this->fail('Se esperaba el rechazo del código ya utilizado.');
        } catch (InstallerException $exception) {
            $this->assertStringContainsString('ya se utilizó', $exception->getMessage());
        }

        $newCode = $installer->enable();
        $this->assertFalse($installer->authorized($authorization));
        $newAuthorization = $installer->prepare($newCode, $connection);
        $this->assertTrue($installer->authorized($newAuthorization));
    }

    public function test_expired_code_disables_installer_until_reenabled(): void
    {
        $installer = app(Installer::class);
        $firstCode = $installer->enable();
        $this->travel(31)->minutes();

        $this->get('https://localhost/instalacion')->assertNotFound();

        $secondCode = $installer->enable();
        $this->assertNotSame($firstCode, $secondCode);
        $this->get('https://localhost/instalacion')->assertOk();
    }

    public function test_environment_writer_preserves_other_settings_and_special_characters(): void
    {
        $path = $this->temporaryDirectory.'/.env';
        file_put_contents($path, "APP_KEY=test-preserved-key\nDB_HOST=old\nDB_URL=old\nOTHER=unchanged\n");
        $password = ' spaces ${OTHER} "quoted" \\ # and \'apostrophe\' ';

        app(InstallerEnvironment::class)->write([
            'host' => 'example.test',
            'port' => '1433',
            'database' => 'test',
            'username' => 'test',
            'password' => $password,
            'trust_certificate' => false,
        ]);

        $saved = Dotenv::parse(file_get_contents($path));
        $this->assertSame('test-preserved-key', $saved['APP_KEY']);
        $this->assertSame('unchanged', $saved['OTHER']);
        $this->assertSame('', $saved['DB_URL']);
        $this->assertSame($password, $saved['DB_PASSWORD']);
    }

    public function test_environment_writer_refuses_duplicate_database_variables_without_modifying_the_file(): void
    {
        $path = $this->temporaryDirectory.'/.env';
        $original = "APP_KEY=test-preserved-key\nDB_HOST=one\nDB_HOST=two\n";
        file_put_contents($path, $original);

        try {
            app(InstallerEnvironment::class)->write([
                'host' => 'example.test',
                'port' => '1433',
                'database' => 'test',
                'username' => 'test',
                'password' => 'test',
                'trust_certificate' => false,
            ]);
            $this->fail('Se esperaba el rechazo de variables duplicadas.');
        } catch (InstallerException $exception) {
            $this->assertSame('El archivo de entorno contiene variables de base duplicadas o multilínea.', $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_environment_writer_refuses_database_assignments_inside_multiline_values(): void
    {
        $path = $this->temporaryDirectory.'/.env';
        $original = "APP_KEY=test-preserved-key\nOTHER=\"first\nDB_HOST=not-an-assignment\nlast\"\n";
        file_put_contents($path, $original);

        try {
            app(InstallerEnvironment::class)->write([
                'host' => 'example.test',
                'port' => '1433',
                'database' => 'test',
                'username' => 'test',
                'password' => 'test',
                'trust_certificate' => false,
            ]);
            $this->fail('Se esperaba el rechazo del valor multilínea.');
        } catch (InstallerException $exception) {
            $this->assertSame('El archivo de entorno contiene variables de base multilínea.', $exception->getMessage());
        }

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_sql_server_installation_preserves_other_objects_and_completes(): void
    {
        $databaseName = getenv('INSTALLER_TEST_DATABASE');
        if (! is_string($databaseName) || $databaseName === '') {
            $this->markTestSkipped('Defina INSTALLER_TEST_DATABASE con una base SQL Server de pruebas sin tablas ni vistas.');
        }

        $sql = config('database.connections.sqlsrv');
        $connection = [
            'host' => (string) $sql['host'],
            'port' => (string) $sql['port'],
            'database' => $databaseName,
            'username' => (string) $sql['username'],
            'password' => (string) $sql['password'],
            'trust_certificate' => filter_var($sql['trust_server_certificate'], FILTER_VALIDATE_BOOL),
        ];
        $installer = app(Installer::class);
        $code = $installer->enable();
        $snapshotConfig = $sql;
        $snapshotConfig['database'] = $databaseName;
        $snapshotConfig['url'] = null;
        config(['database.connections.installer_snapshot' => $snapshotConfig]);
        $objectsBefore = DB::connection('installer_snapshot')->select(
            "SELECT SCHEMA_NAME(schema_id) AS schema_name, name, type FROM sys.objects WHERE is_ms_shipped = 0 AND type IN ('FN', 'IF', 'TF', 'P', 'SN') ORDER BY schema_name, name"
        );

        $response = $this->post('https://localhost/instalacion/conexion', [
            'code' => $code,
            'host' => $connection['host'],
            'port' => $connection['port'],
            'database' => $connection['database'],
            'username' => $connection['username'],
            'db_password' => $connection['password'],
            'trust_certificate' => $connection['trust_certificate'] ? '1' : '0',
        ]);
        $response->assertRedirect(route('installer.show'));

        $cookie = $response->getCookie('panel_install_session', false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookie('panel_install_session', $cookie->getValue());
        $username = 'installer_'.bin2hex(random_bytes(8));
        $admin = [
            'name' => 'Administrador de prueba',
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => bin2hex(random_bytes(24)),
        ];
        $admin['password_confirmation'] = $admin['password'];
        $this->post('https://localhost/instalacion/finalizar', $admin)
            ->assertUnprocessable()->assertSee('La configuración activa no corresponde a la instalación.');
        $this->assertFalse(DB::connection('installer_snapshot')->getSchemaBuilder()->hasTable('users'));

        $saved = Dotenv::parse(file_get_contents($this->temporaryDirectory.'/.env'));
        $this->assertSame('test-preserved-key', $saved['APP_KEY']);
        $this->assertSame($databaseName, $saved['DB_DATABASE']);
        $this->assertTrue($saved['DB_PASSWORD'] === $connection['password']);
        config([
            'database.default' => 'sqlsrv',
            'database.connections.sqlsrv.host' => $saved['DB_HOST'],
            'database.connections.sqlsrv.port' => $saved['DB_PORT'],
            'database.connections.sqlsrv.database' => $saved['DB_DATABASE'],
            'database.connections.sqlsrv.username' => $saved['DB_USERNAME'],
            'database.connections.sqlsrv.password' => $saved['DB_PASSWORD'],
            'database.connections.sqlsrv.url' => null,
            'database.connections.sqlsrv.encrypt' => $saved['DB_ENCRYPT'],
            'database.connections.sqlsrv.trust_server_certificate' => $saved['DB_TRUST_SERVER_CERTIFICATE'],
        ]);
        DB::purge('sqlsrv');

        try {
            $this->get('https://localhost/instalacion')->assertOk()->assertSee($databaseName);
            $this->post('https://localhost/instalacion/finalizar', $admin)->assertOk()->assertSee('Instalación completada');

            $this->assertDatabaseHas('users', ['username' => $username, 'is_admin' => true], 'sqlsrv');
            $this->assertTrue(DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('migrations'));
            $objectsAfter = DB::connection('sqlsrv')->select(
                "SELECT SCHEMA_NAME(schema_id) AS schema_name, name, type FROM sys.objects WHERE is_ms_shipped = 0 AND type IN ('FN', 'IF', 'TF', 'P', 'SN') ORDER BY schema_name, name"
            );
            $this->assertSame(
                array_map(fn ($object): array => (array) $object, $objectsBefore),
                array_map(fn ($object): array => (array) $object, $objectsAfter),
            );
            $this->get('https://localhost/instalacion')->assertNotFound();

            $this->expectException(InstallerException::class);
            $installer->enable();
        } finally {
            if (DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('users')) {
                DB::connection('sqlsrv')->table('users')->where('username', $username)->where('email', $admin['email'])->delete();
            }
        }
    }
}
