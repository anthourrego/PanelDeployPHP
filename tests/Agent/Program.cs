using System.Diagnostics;
using System.Text.Json;

if (OperatingSystem.IsWindows())
    throw new PlatformNotSupportedException("Esta prueba usa el transporte SSH POSIX simulado.");

var fixtures = Path.GetFullPath("tests/Fixtures");
if (!File.Exists(Path.Combine(fixtures, "ssh")))
    throw new InvalidOperationException("Ejecute la prueba desde la raíz del repositorio.");

var repository = Directory.CreateTempSubdirectory("panel-agent-test-");
var root = OperatingSystem.IsMacOS() &&
    (repository.FullName.StartsWith("/var/", StringComparison.Ordinal) || repository.FullName.StartsWith("/tmp/", StringComparison.Ordinal))
    ? "/private" + repository.FullName
    : repository.FullName;
var originalPath = Environment.GetEnvironmentVariable("PATH");
var originalTestRepository = Environment.GetEnvironmentVariable("PANEL_TEST_REPOSITORY");
try
{
    Git("init", "-q");
    Git("config", "user.name", "Agent Test");
    Git("config", "user.email", "agent-test@example.invalid");
    Git("config", "commit.gpgsign", "false");
    const string remote = "git@gitea.test:team/app.git";
    Git("remote", "add", "origin", remote);
    Write("app.php", "v1");
    Write("config.php", "default");
    Write(".gitignore", "ignored.txt\n");
    var baseline = Commit("base");
    Write("app.php", "v2");
    Write("ignored.txt", "versioned");
    Git("add", "-f", "ignored.txt");
    var target = Commit("application");
    Write("config.php", "new default");
    var protectedTarget = Commit("protected");
    Git("checkout", "-q", "--detach", baseline);
    Write("config.php", "local configuration");
    Environment.SetEnvironmentVariable("PATH", fixtures + Path.PathSeparator + originalPath);
    Environment.SetEnvironmentVariable("PANEL_TEST_REPOSITORY", root);

    var job = new Job("claimed", 1, root, remote, target, ["config.php"]);
    Write("ignored.txt", "local ignored file");
    await AssertBlocked(job, "fuera de las rutas protegidas", baseline);
    Assert(Read("ignored.txt") == "local ignored file", "Se sobrescribió un archivo local ignorado.");
    File.Delete(Path.Combine(root, "ignored.txt"));

    await new Deployment().Run(job);
    Assert(Git("rev-parse", "HEAD") == target && Read("app.php") == "v2", "No se desplegó el commit solicitado.");
    Assert(Read("config.php") == "local configuration", "Se modificó el archivo protegido.");
    await AssertBlocked(job with { Commit = protectedTarget }, "cambia una ruta protegida", target);

    var pending = JsonSerializer.Deserialize<Report>(
        """{"target_id":"5","status":"failed","result":"test"}""", Protocol.Json);
    Assert(pending == new Report(5, "failed", "test"), "No se pudo leer un resultado pendiente del agente anterior.");
    var config = JsonSerializer.Deserialize<AgentConfig>(
        """{"PanelUrl":"https://panel.example","Token":"fixture","StateDirectory":"fixture-state"}""", Protocol.Json);
    Assert(config == new AgentConfig("https://panel.example", "fixture", "fixture-state"), "No se pudo leer la configuración existente.");
    Console.WriteLine("Pruebas del agente: correctas.");
}
finally
{
    Environment.SetEnvironmentVariable("PATH", originalPath);
    Environment.SetEnvironmentVariable("PANEL_TEST_REPOSITORY", originalTestRepository);
    repository.Delete(recursive: true);
}

async Task AssertBlocked(Job job, string message, string expectedHead)
{
    var deployment = new Deployment();
    try { await deployment.Run(job); }
    catch (AgentException exception) when (exception.Message.Contains(message, StringComparison.Ordinal))
    {
        Assert(!deployment.CheckoutStarted && Git("rev-parse", "HEAD") == expectedHead,
            "El agente inició el cambio de versión a pesar del bloqueo.");
        return;
    }
    throw new InvalidOperationException("El despliegue no se bloqueó.");
}

void Write(string name, string content) => File.WriteAllText(Path.Combine(root, name), content);
string Read(string name) => File.ReadAllText(Path.Combine(root, name));

string Commit(string message)
{
    Git("add", ".");
    Git("commit", "-qm", message);
    return Git("rev-parse", "HEAD");
}

string Git(params string[] args)
{
    var start = new ProcessStartInfo("git") { WorkingDirectory = root, RedirectStandardOutput = true, UseShellExecute = false };
    foreach (var argument in args)
        start.ArgumentList.Add(argument);
    using var process = Process.Start(start)!;
    var result = process.StandardOutput.ReadToEnd();
    process.WaitForExit();
    Assert(process.ExitCode == 0, "Falló un comando Git de prueba.");
    return result.Trim();
}

static void Assert(bool condition, string message)
{
    if (!condition)
        throw new InvalidOperationException(message);
}
