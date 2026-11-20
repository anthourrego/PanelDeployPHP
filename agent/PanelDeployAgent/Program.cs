using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

if (args is not ["--config", var configPath])
{
    Console.Error.WriteLine("Uso: PanelDeployAgent --config <config.json>");
    return 2;
}

using var mutex = new Mutex(false, @"Global\PanelDeployAgent");
var acquired = false;
try
{
    try { acquired = mutex.WaitOne(0); }
    catch (AbandonedMutexException) { acquired = true; }
    if (!acquired)
        throw new AgentException("Ya hay un agente en ejecución en este servidor.");

    return Run(configPath).GetAwaiter().GetResult();
}
catch (Exception exception) when (exception is AgentException or IOException or UnauthorizedAccessException or
    HttpRequestException or JsonException or OperationCanceledException or ArgumentException)
{
    Console.Error.WriteLine(exception is AgentException ? exception.Message :
        $"El agente no pudo completar la comunicación o guardar el resultado ({exception.GetType().Name}).");
    return 1;
}
finally
{
    if (acquired)
        mutex.ReleaseMutex();
}

static async Task<int> Run(string configPath)
{
    var config = JsonSerializer.Deserialize<AgentConfig>(File.ReadAllText(configPath), Protocol.Json);
    if (config is null || !Protocol.IsHttps(config.PanelUrl) ||
        !Regex.IsMatch(config.Token ?? "", @"\A[1-9][0-9]*\.[a-f0-9]{64}\z") ||
        !Path.IsPathFullyQualified(config.StateDirectory ?? "") || !Directory.Exists(config.StateDirectory))
        throw new AgentException("Configuración inválida: compruebe HTTPS, token y directorio de estado.");

    using var client = new HttpClient(new HttpClientHandler { AllowAutoRedirect = false })
    {
        BaseAddress = new Uri(config.PanelUrl.TrimEnd('/') + "/"),
        Timeout = TimeSpan.FromSeconds(30)
    };
    client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", config.Token);
    client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));

    var journal = Path.Combine(config.StateDirectory, "pending-report.json");
    if (File.Exists(journal))
    {
        var pending = JsonSerializer.Deserialize<Report>(File.ReadAllText(journal), Protocol.Json);
        if (pending is null || pending.TargetId <= 0 ||
            pending.Status is not ("succeeded" or "failed" or "uncertain") || pending.Result is null)
            throw new AgentException("El resultado pendiente es inválido; requiere revisión manual.");
        await SendReport(pending);
        Console.WriteLine("Resultado pendiente entregado.");
        return 0;
    }

    using var claim = await client.PostAsync("api/agent/claim", null);
    claim.EnsureSuccessStatusCode();
    var job = await claim.Content.ReadFromJsonAsync<Job>(Protocol.Json);
    switch (job?.State)
    {
        case "idle":
            Console.WriteLine("Sin trabajos pendientes.");
            return 0;
        case "busy":
            Console.Error.WriteLine("Existe un trabajo activo; no se volverá a ejecutar automáticamente.");
            return 0;
        case "claimed" when job.TargetId > 0:
            break;
        default:
            throw new AgentException("Respuesta de asignación inválida.");
    }

    var deployment = new Deployment();
    Report report;
    try
    {
        await deployment.Run(job);
        report = new(job.TargetId, "succeeded", "Commit verificado y desplegado.");
    }
    catch (Exception exception) when (exception is AgentException or IOException or
        UnauthorizedAccessException or System.ComponentModel.Win32Exception or ArgumentException)
    {
        report = deployment.CheckoutStarted
            ? new(job.TargetId, "uncertain", "El cambio de versión pudo quedar incompleto; requiere revisión manual.")
            : new(job.TargetId, "failed", exception is AgentException
                ? exception.Message : "Error inesperado durante el despliegue. Revise el servidor.");
    }

    var temporary = journal + ".tmp";
    await using (var stream = new FileStream(temporary, FileMode.Create, FileAccess.Write, FileShare.None))
    {
        await JsonSerializer.SerializeAsync(stream, report, Protocol.Json);
        stream.Flush(flushToDisk: true);
    }
    File.Move(temporary, journal);
    await SendReport(report);
    Console.WriteLine($"Resultado registrado: {report.Status}.");
    return 0;

    async Task SendReport(Report result)
    {
        using var response = await client.PostAsJsonAsync(
            $"api/agent/targets/{result.TargetId}/report", new { status = result.Status, result = result.Result });
        response.EnsureSuccessStatusCode();
        if ((await response.Content.ReadFromJsonAsync<Acknowledgment>(Protocol.Json))?.State != "recorded")
            throw new AgentException("El panel no confirmó el resultado.");
        File.Delete(journal);
    }
}

internal sealed record AgentConfig(
    [property: JsonPropertyName("PanelUrl")] string PanelUrl,
    string Token,
    [property: JsonPropertyName("StateDirectory")] string StateDirectory);
internal sealed record Job(string State, long TargetId, string Path, string RepositoryUrl, string Commit, string[] PreservedPaths);
internal sealed record Report(long TargetId, string Status, string Result);
internal sealed record Acknowledgment(string State);
internal sealed class AgentException(string message) : Exception(message);

internal static class Protocol
{
    internal static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
        NumberHandling = JsonNumberHandling.AllowReadingFromString
    };

    internal static bool IsHttps(string? value) =>
        Uri.TryCreate(value, UriKind.Absolute, out var uri) && uri.Scheme == Uri.UriSchemeHttps &&
        uri.Host.Length > 0 && uri.UserInfo.Length == 0 && uri.Query.Length == 0 && uri.Fragment.Length == 0;
}
