using System.Globalization;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Xml.Linq;

const string taskName = "PanelDeployAgent";
try
{
    using var identity = WindowsIdentity.GetCurrent();
    if (!new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator))
        throw new InvalidOperationException("Ejecute el instalador como administrador.");

    var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "PanelDeploy");
    dynamic scheduler = Activator.CreateInstance(Type.GetTypeFromProgID("Schedule.Service", throwOnError: true)!)!;
    scheduler.Connect();
    dynamic folder = scheduler.GetFolder(@"\");
    if (Path.Exists(root) || TaskExists(folder))
        throw new InvalidOperationException("Ya existe la carpeta o tarea PanelDeployAgent. No se sobrescribió nada.");

    Console.Write("URL HTTPS del panel: ");
    var input = Console.ReadLine()?.Trim();
    if (!Uri.TryCreate(input, UriKind.Absolute, out var panel) || panel.Scheme != Uri.UriSchemeHttps ||
        panel.Host.Length == 0 || panel.UserInfo.Length != 0 || panel.Query.Length != 0 || panel.Fragment.Length != 0)
        throw new InvalidOperationException("La URL del panel debe usar HTTPS sin credenciales, consulta ni fragmento.");
    Console.Write(@"Cuenta Windows para la tarea (DOMINIO\usuario o EQUIPO\usuario): ");
    var account = Console.ReadLine()?.Trim();
    if (string.IsNullOrWhiteSpace(account))
        throw new InvalidOperationException("No se indicó la cuenta para la tarea.");
    var accountSid = (SecurityIdentifier)new NTAccount(account).Translate(typeof(SecurityIdentifier));
    var password = ReadSecret("Contraseña de la cuenta Windows: ");
    if (password.Length == 0)
        throw new InvalidOperationException("No se indicó la contraseña de la tarea.");
    var token = ReadSecret("Token del servidor generado en PanelDeploy: ");
    if (!Regex.IsMatch(token, @"\A[1-9][0-9]*\.[a-f0-9]{64}\z"))
        throw new InvalidOperationException("El token no tiene el formato esperado.");

    var state = Path.Combine(root, "estado");
    var executable = Path.Combine(root, "PanelDeployAgent.exe");
    var configPath = Path.Combine(root, "config.json");
    using var payload = Assembly.GetExecutingAssembly().GetManifestResourceStream(taskName + ".exe") ??
        throw new InvalidOperationException("El instalador no contiene el agente.");
    NativeDirectory.Create(root, DirectoryAcl(accountSid, FileSystemRights.ReadAndExecute));
    NativeDirectory.Create(state, DirectoryAcl(accountSid, FileSystemRights.Modify));
    using (var file = new FileStream(executable, FileMode.CreateNew, FileAccess.Write, FileShare.None))
        payload.CopyTo(file);
    using (var file = new FileStream(configPath, FileMode.CreateNew, FileAccess.Write, FileShare.None))
        JsonSerializer.Serialize(file, new { PanelUrl = panel.AbsoluteUri.TrimEnd('/'), Token = token, StateDirectory = state });

    XNamespace ns = "http://schemas.microsoft.com/windows/2004/02/mit/task";
    XElement Xml(string name, params object[] content) => new(ns + name, content);
    var task = Xml("Task", new XAttribute("version", "1.2"),
        Xml("Triggers", Xml("CalendarTrigger",
            Xml("Repetition", Xml("Interval", "PT5M"), Xml("Duration", "P1D"), Xml("StopAtDurationEnd", "false")),
            Xml("StartBoundary", DateTime.Today.ToString("yyyy-MM-dd'T'HH:mm:ss", CultureInfo.InvariantCulture)),
            Xml("Enabled", "true"),
            Xml("ScheduleByDay", Xml("DaysInterval", "1")))),
        Xml("Principals", Xml("Principal", new XAttribute("id", "Agent"),
            Xml("UserId", accountSid.Value), Xml("LogonType", "Password"), Xml("RunLevel", "LeastPrivilege"))),
        Xml("Settings", Xml("MultipleInstancesPolicy", "IgnoreNew"),
            Xml("StartWhenAvailable", "true"), Xml("Enabled", "false")),
        Xml("Actions", new XAttribute("Context", "Agent"),
            Xml("Exec", Xml("Command", executable), Xml("Arguments", $"--config \"{configPath}\""),
                Xml("WorkingDirectory", root))));
    const int taskCreate = 2;
    const int taskLogonPassword = 1;
    folder.RegisterTask(taskName, task.ToString(SaveOptions.DisableFormatting), taskCreate, account, password, taskLogonPassword, null);
    Console.WriteLine("Agente instalado. La tarea queda deshabilitada hasta que se valide en el servidor.");
    Pause();
    return 0;
}
catch (Exception exception) when (exception is InvalidOperationException or IdentityNotMappedException or
    COMException or UnauthorizedAccessException or IOException or ArgumentException or System.ComponentModel.Win32Exception)
{
    Console.Error.WriteLine(exception is InvalidOperationException ? exception.Message :
        $"La instalación no terminó ({exception.GetType().Name}, 0x{exception.HResult:X8}). Revise los permisos, la cuenta y cualquier instalación parcial.");
    Pause();
    return 1;
}

static bool TaskExists(dynamic folder)
{
    const int notFound = unchecked((int)0x80070002);
    try { return folder.GetTask(taskName) is not null; }
    catch (COMException exception) when (exception.HResult == notFound) { return false; }
}

static DirectorySecurity DirectoryAcl(SecurityIdentifier account, FileSystemRights accountRights)
{
    var acl = new DirectorySecurity();
    acl.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
    Grant(new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null), FileSystemRights.FullControl);
    Grant(new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null), FileSystemRights.FullControl);
    Grant(account, accountRights);
    return acl;

    void Grant(SecurityIdentifier sid, FileSystemRights rights) =>
        acl.AddAccessRule(new FileSystemAccessRule(sid, rights,
            InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
}

static string ReadSecret(string prompt)
{
    Console.Write(prompt);
    var value = new StringBuilder();
    while (true)
    {
        var key = Console.ReadKey(intercept: true);
        if (key.Key == ConsoleKey.Enter)
        {
            Console.WriteLine();
            return value.ToString();
        }
        if (key.Key == ConsoleKey.Backspace && value.Length > 0)
            value.Length--;
        else if (!char.IsControl(key.KeyChar))
            value.Append(key.KeyChar);
    }
}

static void Pause()
{
    if (Console.IsInputRedirected)
        return;
    Console.WriteLine("Pulse una tecla para cerrar.");
    Console.ReadKey(intercept: true);
}

internal static class NativeDirectory
{
    internal static void Create(string path, DirectorySecurity security)
    {
        // Unlike DirectoryInfo.Create, this fails if the name already exists.
        var descriptor = security.GetSecurityDescriptorBinaryForm();
        var pinned = GCHandle.Alloc(descriptor, GCHandleType.Pinned);
        try
        {
            var attributes = new SecurityAttributes
            {
                Length = Marshal.SizeOf<SecurityAttributes>(),
                Descriptor = pinned.AddrOfPinnedObject()
            };
            if (!CreateDirectory(path, ref attributes))
                throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
        }
        finally
        {
            pinned.Free();
        }
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct SecurityAttributes
    {
        public int Length;
        public IntPtr Descriptor;
        public int InheritHandle;
    }

    [DllImport("kernel32.dll", EntryPoint = "CreateDirectoryW", CharSet = CharSet.Unicode, ExactSpelling = true, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CreateDirectory(string path, ref SecurityAttributes attributes);
}
