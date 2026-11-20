using System.Diagnostics;
using System.Text;
using System.Text.RegularExpressions;

internal sealed class Deployment
{
    private static readonly Regex SshUrl = new(
        @"\A(?:ssh://[A-Za-z0-9_][A-Za-z0-9._-]*@(?:[A-Za-z0-9_][A-Za-z0-9._-]*|\[[0-9A-Fa-f:]+\])(?::[0-9]{1,5})?/|[A-Za-z0-9_][A-Za-z0-9._-]*@(?:[A-Za-z0-9_][A-Za-z0-9._-]*|\[[0-9A-Fa-f:]+\]):)[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git\z");
    private static readonly Regex InvalidComponent = new(
        """\A(?:\.{1,2}|\.git|(?:con|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])(?:\..*)?)\z|[. ]\z|[\\<>:"|?*\x00-\x1f]""",
        RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
    private const StringComparison WindowsPath = StringComparison.OrdinalIgnoreCase;

    internal bool CheckoutStarted { get; private set; }

    internal async Task Run(Job job)
    {
        if (job.Commit is not { } commit || !Regex.IsMatch(commit, @"\A[a-f0-9]{40}\z"))
            throw new AgentException("El commit no es válido.");
        var ssh = SshUrl.IsMatch(job.RepositoryUrl ?? "");
        if (!ssh && !Protocol.IsHttps(job.RepositoryUrl))
            throw new AgentException("El repositorio debe utilizar SSH o HTTPS sin contraseñas en la URL.");
        if (job.PreservedPaths is null || job.PreservedPaths.Any(path => !SafeRelative(path)))
            throw new AgentException("La lista de rutas protegidas no es válida.");
        if (!Path.IsPathFullyQualified(job.Path ?? "") || !Directory.Exists(job.Path))
            throw new AgentException("La carpeta del proyecto no existe o no es absoluta.");

        var root = Path.TrimEndingDirectorySeparator(Path.GetFullPath(job.Path));
        for (var directory = new DirectoryInfo(root); directory is not null; directory = directory.Parent)
            RejectReparsePoint(directory.FullName);

        var gitRoot = await Git("verificación de repositorio", "rev-parse", "--show-toplevel");
        if (!Path.TrimEndingDirectorySeparator(Path.GetFullPath(gitRoot.TrimEnd('\r', '\n'))).Equals(root, WindowsPath))
            throw new AgentException("La ruta configurada no es la raíz del repositorio Git.");
        var remote = await Git("verificación del remoto", "remote", "get-url", "--all", "origin");
        if (!remote.TrimEnd('\r', '\n').Equals(job.RepositoryUrl, StringComparison.Ordinal))
            throw new AgentException("El remoto origin no coincide exactamente con el repositorio configurado.");

        foreach (var marker in new[] { "MERGE_HEAD", "CHERRY_PICK_HEAD", "REVERT_HEAD", "rebase-merge", "rebase-apply", "BISECT_LOG" })
        {
            var markerPath = await Git("verificación del estado de Git", "rev-parse", "--git-path", marker);
            if (Path.Exists(Path.Combine(root, markerPath.TrimEnd('\r', '\n'))))
                throw new AgentException("Hay una operación Git en curso.");
        }

        await Git("descarga del remoto", "fetch", "--no-tags", "origin");
        await Git("verificación del commit", "cat-file", "-e", commit + "^{commit}");
        foreach (var entry in Entries(await Git("verificación del índice", "ls-files", "-v", "-z")))
        {
            if (entry[0] is >= 'a' and <= 'z' or 'S')
                throw new AgentException("El índice contiene archivos ocultos a la verificación de cambios.");
        }
        foreach (var tree in new[] { "HEAD", commit })
        {
            foreach (var entry in Entries(await Git("verificación del árbol", "ls-tree", "-r", "-z", "--full-tree", tree)))
            {
                var separator = entry.IndexOf('\t');
                if (separator < 0 || !SafeRelative(entry[(separator + 1)..]) ||
                    entry.StartsWith("120000 ", StringComparison.Ordinal) || entry.StartsWith("160000 ", StringComparison.Ordinal))
                    throw new AgentException("El repositorio contiene enlaces, submódulos o rutas no compatibles.");
            }
        }
        foreach (var path in Entries(await Git("comparación del commit", "diff", "-z", "--name-only", "--no-renames", "HEAD", commit, "--")))
        {
            if (!SafeRelative(path))
                throw new AgentException("El commit contiene una ruta no compatible.");
            if (Preserved(path) || job.PreservedPaths.Any(item => item.StartsWith(path + "/", WindowsPath)))
                throw new AgentException("El commit cambia una ruta protegida; requiere revisión manual.");
            var current = root;
            foreach (var part in path.Split('/'))
            {
                current = Path.Combine(current, part);
                RejectReparsePoint(current);
            }
        }
        foreach (var entry in Entries(await Git("comprobación de cambios locales", "status", "--porcelain=v1", "-z", "--untracked-files=all", "--ignored=matching", "--no-renames")))
        {
            if (entry.Length < 4 || entry[..2].Contains('U') || entry[..2] is "AA" or "DD" ||
                !SafeRelative(entry[3..].TrimEnd('/')))
                throw new AgentException("El estado de Git contiene cambios o rutas que no se pueden verificar.");
            if (!Preserved(entry[3..].TrimEnd('/')))
                throw new AgentException("Se encontraron cambios o archivos locales fuera de las rutas protegidas.");
        }

        CheckoutStarted = true;
        await Git("cambio de versión", "checkout", "--no-overwrite-ignore", "--detach", commit);
        var actual = await Git("verificación final", "rev-parse", "HEAD");
        if (actual.TrimEnd('\r', '\n') != commit)
            throw new AgentException("No se pudo verificar el commit desplegado.");
        CheckoutStarted = false;

        bool Preserved(string path) => job.PreservedPaths.Any(item =>
            path.Equals(item, WindowsPath) || path.StartsWith(item + "/", WindowsPath));

        async Task<string> Git(string operation, params string[] arguments)
        {
            var start = new ProcessStartInfo("git")
            {
                WorkingDirectory = root,
                UseShellExecute = false,
                RedirectStandardInput = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                StandardOutputEncoding = new UTF8Encoding(false, true),
                CreateNoWindow = true
            };
            foreach (var argument in new[] { "-c", OperatingSystem.IsWindows() ? "core.hooksPath=NUL" : "core.hooksPath=/dev/null",
                "-c", "core.fsmonitor=false", "-c", "submodule.recurse=false", "-c", "protocol.allow=never",
                "-c", "protocol.https.allow=always", "-c", "protocol.ssh.allow=always",
                "-c", "http.sslVerify=true", "-c", "http.followRedirects=false" }.Concat(arguments))
                start.ArgumentList.Add(argument);
            foreach (var variable in new[] { "GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_SSH", "GIT_SSH_COMMAND", "GIT_SSL_NO_VERIFY", "GIT_CONFIG_COUNT", "GIT_CONFIG_PARAMETERS", "GIT_ALLOW_PROTOCOL" })
                start.Environment.Remove(variable);
            start.Environment["GIT_TERMINAL_PROMPT"] = "0";
            start.Environment["GCM_INTERACTIVE"] = "Never";
            if (ssh)
            {
                start.Environment["GIT_SSH_COMMAND"] = "ssh -o BatchMode=yes -o StrictHostKeyChecking=yes";
                start.Environment["GIT_SSH_VARIANT"] = "ssh";
            }
            using var process = Process.Start(start) ?? throw new AgentException("No se pudo iniciar Git.");
            process.StandardInput.Close();
            var output = process.StandardOutput.ReadToEndAsync();
            var drain = process.StandardError.BaseStream.CopyToAsync(Stream.Null);
            await process.WaitForExitAsync();
            await drain;
            if (process.ExitCode != 0)
                throw new AgentException($"Git falló durante {operation} (código {process.ExitCode}).");
            return await output;
        }
    }

    private static bool SafeRelative(string? path) => !string.IsNullOrEmpty(path) &&
        path.Split('/').All(part => part.Length > 0 && !InvalidComponent.IsMatch(part));

    private static string[] Entries(string output) => output.Split('\0', StringSplitOptions.RemoveEmptyEntries);

    private static void RejectReparsePoint(string path)
    {
        try
        {
            if ((File.GetAttributes(path) & FileAttributes.ReparsePoint) != 0)
                throw new AgentException("La ruta afectada contiene un enlace o punto de reanálisis.");
        }
        catch (FileNotFoundException) { }
        catch (DirectoryNotFoundException) { }
    }
}
