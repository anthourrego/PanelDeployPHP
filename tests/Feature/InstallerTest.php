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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_home_redirects_to_pending_local_installer_before_starting_the_database_session(): void
    {
        app(Installer::class)->enable(local: true);
        config(['session.driver' => 'database']);

        $this->get('http://localhost/')->assertRedirect('http://localhost/instalacion');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->get('http://localhost/')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '192.0.2.1')->get('http://localhost/')->assertNotFound();
    }

    public function test_home_uses_normal_authentication_when_installer_is_not_active(): void
    {
        $this->get('http://localhost/')->assertRedirect(route('login'));
        app(Installer::class)->enable(local: true);
        file_put_contents($this->temporaryDirectory.'/app/private/panel-installer.json', '{"phase":"complete","local":true}');

        $this->get('http://localhost/')->assertRedirect(route('login'));
    }

    public function test_home_redirects_to_the_manually_enabled_installer_only_over_https(): void
    {
        app(Installer::class)->enable();

        $this->get('https://localhost/')->assertRedirect('https://localhost/instalacion');
        $this->get('http://localhost/')->assertNotFound();
    }

    public function test_local_installer_remains_available_without_a_code_only_on_loopback(): void
    {
        app(Installer::class)->enable(local: true);
        $this->travel(31)->days();

        $response = $this->get('http://localhost/instalacion')->assertOk()->assertDontSee('Código temporal');
        $this->assertStringContainsString("img-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('script-src', $response->headers->get('Content-Security-Policy'));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->get('http://localhost/instalacion')->assertNotFound();
        $this->post('http://localhost/instalacion/conexion', [])->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('http://example.test/instalacion')->assertNotFound();
        $this->withHeader('X-Forwarded-For', '192.0.2.1')
            ->get('http://localhost/instalacion')->assertNotFound();
    }

    public function test_local_installer_validates_connection_without_a_code(): void
    {
        app(Installer::class)->enable(local: true);
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

        $response = $this->post('http://localhost/instalacion/conexion', [
            'host' => 'localhost',
            'port' => '1433',
            'database' => 'test',
            'username' => 'test',
            'db_password' => 'secret-for-test',
        ])->assertRedirect(route('installer.show'));

        $this->withUnencryptedCookie('panel_install_session', $response->getCookie('panel_install_session', false)->getValue())
            ->get('http://localhost/instalacion')->assertOk()->assertSee('Nombre del administrador');
        $this->assertSame('test', Dotenv::parse(file_get_contents($this->temporaryDirectory.'/.env'))['DB_DATABASE']);
    }

    public function test_mysql_rejects_remote_hosts_without_changing_configuration(): void
    {
        app(Installer::class)->enable(local: true);
        $path = $this->temporaryDirectory.'/.env';
        $original = file_get_contents($path);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'mysql',
            'host' => 'example.test',
            'port' => '3306',
            'database' => 'test',
            'username' => 'test',
        ])->assertUnprocessable();

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_connection_validation_error_preserves_non_sensitive_fields_safely(): void
    {
        app(Installer::class)->enable(local: true);

        $response = $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'mysql',
            'host' => 'example.test',
            'port' => '3306',
            'database' => 'test_database',
            'username' => '<script>alert(1)</script>',
            'db_password' => 'secret-for-test',
        ])->assertUnprocessable();

        $response->assertSee('value="mysql" selected', false)
            ->assertSee('value="example.test"', false)
            ->assertSee('value="3306"', false)
            ->assertSee('value="test_database"', false)
            ->assertSee('value="&lt;script&gt;alert(1)&lt;/script&gt;"', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('secret-for-test');
    }

    public function test_connection_validation_handles_non_scalar_input(): void
    {
        app(Installer::class)->enable(local: true);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => ['mysql'],
            'host' => ['localhost'],
            'port' => ['3306'],
            'database' => ['test_database'],
            'username' => ['test_user'],
            'trust_certificate' => ['1'],
        ])->assertUnprocessable();
    }

    public static function missingDatabaseCases(): array
    {
        return [
            'mysql' => ['mysql', 1049, null],
            'sql server' => ['sqlsrv', 4060, null],
            'mysql created concurrently' => ['mysql', 1049, 1007],
            'sql server created concurrently' => ['sqlsrv', 4060, 1801],
        ];
    }

    #[DataProvider('missingDatabaseCases')]
    public function test_missing_database_is_created_or_rechecked_after_a_creation_race(string $driver, int $missingCode, ?int $existsCode): void
    {
        app(Installer::class)->enable(local: true);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException($missingCode));
        $server = \Mockery::mock(Connection::class);
        if ($driver === 'sqlsrv') {
            $server->shouldReceive('selectOne')->once()
                ->with("SELECT DB_ID(?) AS database_id, HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW ANY DATABASE') AS can_view", ['test-database'])
                ->andReturn((object) ['database_id' => null, 'can_view' => 1]);
        }
        $create = $server->shouldReceive('statement')->once()
            ->with($driver === 'mysql' ? 'CREATE DATABASE `test-database`' : 'CREATE DATABASE [test-database]');
        if ($existsCode === null) {
            $create->andReturn(true);
        } else {
            $create->andThrow($this->databaseException($existsCode));
        }
        $target = \Mockery::mock(Connection::class);
        if ($driver === 'mysql') {
            $target->shouldReceive('selectOne')->once()->with('SELECT DATABASE() AS database_name')
                ->andReturn((object) ['database_name' => 'test-database']);
            $target->shouldReceive('selectOne')->once()->with(\Mockery::on(fn ($sql): bool => str_contains($sql, 'information_schema.USER_PRIVILEGES')))
                ->andReturn((object) ['can_view' => 1]);
            $target->shouldReceive('selectOne')->once()->with('SELECT COUNT(*) AS object_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
                ->andReturn((object) ['object_count' => 0]);
        } else {
            $target->shouldReceive('selectOne')->once()
                ->with("SELECT DB_NAME() AS database_name, HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'VIEW DEFINITION') AS can_view")
                ->andReturn((object) ['database_name' => 'test-database', 'can_view' => 1]);
            $target->shouldReceive('selectOne')->once()
                ->with("SELECT COUNT(*) AS object_count FROM sys.objects WHERE is_ms_shipped = 0 AND type IN ('U', 'V')")
                ->andReturn((object) ['object_count' => 0]);
        }
        DB::shouldReceive('connection')->with('installer')->twice()->andReturn($initial, $target);
        DB::shouldReceive('connection')->with('installer_server')->once()->andReturnUsing(function () use ($driver, $server) {
            $this->assertSame($driver === 'mysql' ? '' : 'master', config('database.connections.installer_server.database'));

            return $server;
        });
        DB::shouldReceive('purge')->with('installer')->times(3);
        DB::shouldReceive('purge')->with('installer_server')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => $driver,
            'host' => 'localhost',
            'port' => $driver === 'mysql' ? '3306' : '1433',
            'database' => 'test-database',
            'username' => 'test',
        ])->assertRedirect(route('installer.show'));

        $this->assertSame('test-database', Dotenv::parse(file_get_contents($this->temporaryDirectory.'/.env'))['DB_DATABASE']);
        $this->assertNull(config('database.connections.installer_server'));
    }

    public static function creationFailureCases(): array
    {
        return [
            'mysql' => ['mysql', 1049],
            'sql server' => ['sqlsrv', 4060],
        ];
    }

    #[DataProvider('creationFailureCases')]
    public function test_creation_failure_requests_a_manually_created_database(string $driver, int $missingCode): void
    {
        app(Installer::class)->enable(local: true);
        $path = $this->temporaryDirectory.'/.env';
        $original = file_get_contents($path);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException($missingCode));
        $server = \Mockery::mock(Connection::class);
        if ($driver === 'sqlsrv') {
            $server->shouldReceive('selectOne')->once()->andReturn((object) ['database_id' => null, 'can_view' => 1]);
        }
        $server->shouldReceive('statement')->once()->andThrow($this->databaseException(1234));
        DB::shouldReceive('connection')->with('installer')->once()->andReturn($initial);
        DB::shouldReceive('connection')->with('installer_server')->once()->andReturn($server);
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('purge')->with('installer_server')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => $driver,
            'host' => 'localhost',
            'port' => $driver === 'mysql' ? '3306' : '1433',
            'database' => 'test-database',
            'username' => 'test',
            'db_password' => 'secret-for-test',
        ])->assertUnprocessable()
            ->assertSee('No se pudo crear la base de datos')
            ->assertSee('administrador que la cree')
            ->assertDontSee('secret-for-test');

        $this->assertSame($original, file_get_contents($path));
        $this->assertNull(config('database.connections.installer_server'));
    }

    public function test_sql_server_does_not_create_a_database_without_catalog_visibility(): void
    {
        app(Installer::class)->enable(local: true);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException(4060));
        $server = \Mockery::mock(Connection::class);
        $server->shouldReceive('selectOne')->once()->andReturn((object) ['database_id' => null, 'can_view' => 0]);
        $server->shouldNotReceive('statement');
        DB::shouldReceive('connection')->with('installer')->once()->andReturn($initial);
        DB::shouldReceive('connection')->with('installer_server')->once()->andReturn($server);
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('purge')->with('installer_server')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'sqlsrv',
            'host' => 'localhost',
            'port' => '1433',
            'database' => 'test-database',
            'username' => 'test',
        ])->assertUnprocessable()->assertSee('No se pudo determinar si la base de datos existe');
    }

    public function test_sql_server_does_not_create_a_database_that_exists_but_is_inaccessible(): void
    {
        app(Installer::class)->enable(local: true);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException(4060));
        $server = \Mockery::mock(Connection::class);
        $server->shouldReceive('selectOne')->once()->andReturn((object) ['database_id' => 42, 'can_view' => 1]);
        $server->shouldNotReceive('statement');
        DB::shouldReceive('connection')->with('installer')->once()->andReturn($initial);
        DB::shouldReceive('connection')->with('installer_server')->once()->andReturn($server);
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('purge')->with('installer_server')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'sqlsrv',
            'host' => 'localhost',
            'port' => '1433',
            'database' => 'test-database',
            'username' => 'test',
        ])->assertUnprocessable()->assertSee('La base de datos existe, pero no se pudo acceder');
    }

    public function test_sql_server_reports_when_master_cannot_be_accessed(): void
    {
        app(Installer::class)->enable(local: true);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException(4060));
        $server = \Mockery::mock(Connection::class);
        $server->shouldReceive('selectOne')->once()->andThrow($this->databaseException(18456));
        $server->shouldNotReceive('statement');
        DB::shouldReceive('connection')->with('installer')->once()->andReturn($initial);
        DB::shouldReceive('connection')->with('installer_server')->once()->andReturn($server);
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('purge')->with('installer_server')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'sqlsrv',
            'host' => 'localhost',
            'port' => '1433',
            'database' => 'test-database',
            'username' => 'test',
        ])->assertUnprocessable()
            ->assertSee('No se pudo comprobar o crear la base de datos')
            ->assertSee('administrador que la cree');
    }

    public function test_access_denied_does_not_attempt_to_create_a_database(): void
    {
        app(Installer::class)->enable(local: true);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException(1044));
        DB::shouldReceive('connection')->with('installer')->once()->andReturn($initial);
        DB::shouldNotReceive('connection')->with('installer_server');
        DB::shouldReceive('purge')->with('installer')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'test-database',
            'username' => 'test',
        ])->assertUnprocessable()
            ->assertSee('No se pudo validar la conexión')
            ->assertDontSee('No se pudo crear la base de datos');
    }

    public function test_database_created_concurrently_is_rejected_if_it_contains_tables(): void
    {
        app(Installer::class)->enable(local: true);
        $path = $this->temporaryDirectory.'/.env';
        $original = file_get_contents($path);
        $initial = \Mockery::mock(Connection::class);
        $initial->shouldReceive('selectOne')->once()->andThrow($this->databaseException(1049));
        $server = \Mockery::mock(Connection::class);
        $server->shouldReceive('statement')->once()->andThrow($this->databaseException(1007));
        $target = \Mockery::mock(Connection::class);
        $target->shouldReceive('selectOne')->once()->with('SELECT DATABASE() AS database_name')
            ->andReturn((object) ['database_name' => 'test-database']);
        $target->shouldReceive('selectOne')->once()->with(\Mockery::on(fn ($sql): bool => str_contains($sql, 'information_schema.USER_PRIVILEGES')))
            ->andReturn((object) ['can_view' => 1]);
        $target->shouldReceive('selectOne')->once()->with('SELECT COUNT(*) AS object_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
            ->andReturn((object) ['object_count' => 1]);
        DB::shouldReceive('connection')->with('installer')->twice()->andReturn($initial, $target);
        DB::shouldReceive('connection')->with('installer_server')->once()->andReturn($server);
        DB::shouldReceive('purge')->with('installer')->times(3);
        DB::shouldReceive('purge')->with('installer_server')->twice();
        DB::shouldReceive('getConnections')->andReturn([]);

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'test-database',
            'username' => 'test',
        ])->assertUnprocessable()->assertSee('La base contiene tablas o vistas');

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_mysql_creates_a_missing_database_on_an_isolated_test_server(): void
    {
        if (getenv('INSTALLER_ISOLATED_MYSQL_PORT') !== '23306' || ! is_string(getenv('INSTALLER_ISOLATED_MYSQL_PASSWORD'))) {
            $this->markTestSkipped('La prueba requiere un servidor MySQL aislado de pruebas en el puerto 23306.');
        }

        app(Installer::class)->enable(local: true);
        $database = 'panel_installer_'.bin2hex(random_bytes(8));

        $this->post('http://localhost/instalacion/conexion', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '23306',
            'database' => $database,
            'username' => 'root',
            'db_password' => getenv('INSTALLER_ISOLATED_MYSQL_PASSWORD'),
        ])->assertRedirect(route('installer.show'));

        $this->assertSame($database, Dotenv::parse(file_get_contents($this->temporaryDirectory.'/.env'))['DB_DATABASE']);
    }

    private function databaseException(int $code): PDOException
    {
        $exception = new PDOException('database error');
        $exception->errorInfo = ['42000', $code, 'database error'];

        return $exception;
    }

    public function test_mysql_requires_visibility_of_the_entire_database_and_rejects_existing_tables(): void
    {
        app(Installer::class)->enable(local: true);
        $path = $this->temporaryDirectory.'/.env';
        $original = file_get_contents($path);
        $database = $this->mock(Connection::class);
        $database->shouldReceive('selectOne')->with('SELECT DATABASE() AS database_name')->twice()
            ->andReturn((object) ['database_name' => 'test']);
        $database->shouldReceive('selectOne')->with(\Mockery::on(fn ($sql): bool => str_contains($sql, 'information_schema.USER_PRIVILEGES')))->twice()
            ->andReturn((object) ['can_view' => 0], (object) ['can_view' => 1]);
        $database->shouldReceive('selectOne')->with('SELECT COUNT(*) AS object_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->once()
            ->andReturn((object) ['object_count' => 1]);
        DB::shouldReceive('connection')->with('installer')->twice()->andReturn($database);
        DB::shouldReceive('purge')->with('installer')->times(4);
        DB::shouldReceive('getConnections')->andReturn([]);
        $input = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'test', 'username' => 'test'];

        $this->post('http://localhost/instalacion/conexion', $input)->assertUnprocessable();
        $this->post('http://localhost/instalacion/conexion', $input)->assertUnprocessable();

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_mysql_local_installation_runs_migrations_and_creates_administrator(): void
    {
        if (getenv('INSTALLER_TEST_MYSQL_DATABASE') !== 'panel_installer_test_copilot') {
            $this->markTestSkipped('La prueba requiere la base MySQL temporal panel_installer_test_copilot.');
        }

        app(Installer::class)->enable(local: true);
        $input = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'panel_installer_test_copilot',
            'username' => 'root',
            'db_password' => '',
        ];
        config(['database.connections.installer_test_mysql' => array_merge(config('database.connections.mysql'), [
            'url' => null,
            'host' => $input['host'],
            'port' => $input['port'],
            'database' => $input['database'],
            'username' => $input['username'],
            'password' => $input['db_password'],
            'unix_socket' => '',
        ])]);
        $testDatabase = DB::connection('installer_test_mysql');
        $testDatabase->statement('CREATE TABLE installer_existing (id INT PRIMARY KEY)');
        try {
            $this->post('http://localhost/instalacion/conexion', $input)->assertUnprocessable();
            $this->assertTrue($testDatabase->getSchemaBuilder()->hasTable('installer_existing'));
        } finally {
            $testDatabase->statement('DROP TABLE installer_existing');
        }

        $response = $this->post('http://localhost/instalacion/conexion', $input)
            ->assertRedirect(route('installer.show'));

        $saved = Dotenv::parse(file_get_contents($this->temporaryDirectory.'/.env'));
        $this->assertSame('mysql', $saved['DB_CONNECTION']);
        $this->assertSame('panel_installer_test_copilot', $saved['DB_DATABASE']);
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => $saved['DB_HOST'],
            'database.connections.mysql.port' => $saved['DB_PORT'],
            'database.connections.mysql.database' => $saved['DB_DATABASE'],
            'database.connections.mysql.username' => $saved['DB_USERNAME'],
            'database.connections.mysql.password' => $saved['DB_PASSWORD'],
            'database.connections.mysql.unix_socket' => '',
        ]);
        DB::purge('mysql');
        $this->withUnencryptedCookie('panel_install_session', $response->getCookie('panel_install_session', false)->getValue());
        $this->get('http://localhost/instalacion')->assertOk()->assertSee('panel_installer_test_copilot');
        $this->post('http://localhost/instalacion/finalizar', [
            'name' => 'Administrador de prueba',
            'username' => 'installer_test',
            'email' => 'installer_test@example.test',
            'password' => 'temporary-test-password',
            'password_confirmation' => 'temporary-test-password',
        ])->assertOk()->assertSee('Instalación completada');

        $this->assertDatabaseHas('users', ['username' => 'installer_test', 'is_admin' => true], 'mysql');
        $this->get('http://localhost/instalacion')->assertNotFound();
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
            'driver' => 'sqlsrv',
            'host' => 'example.test',
            'port' => '1433',
            'database' => 'test',
            'username' => 'test',
            'db_password' => 'secret-for-test',
            'trust_certificate' => '1',
        ])->assertUnprocessable()
            ->assertSee('No se pudo validar la conexión')
            ->assertSee('value="sqlsrv" selected', false)
            ->assertSee('value="example.test"', false)
            ->assertSee('value="1433"', false)
            ->assertSee('name="database" value="test"', false)
            ->assertSee('name="username" value="test"', false)
            ->assertSee('aria-describedby="certificate-hint" checked', false)
            ->assertDontSee('secret-for-test')
            ->assertDontSee($code)
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
