param(
    [Parameter(Mandatory = $true)]
    [string] $PanelUrl
)

$ErrorActionPreference = 'Stop'
$principal = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Ejecute el instalador como administrador.'
}

$panelUri = $null
if (-not [Uri]::TryCreate($PanelUrl, [UriKind]::Absolute, [ref] $panelUri) -or
    $panelUri.Scheme -ne 'https' -or $panelUri.UserInfo -or $panelUri.Query -or $panelUri.Fragment) {
    throw 'La URL del panel debe utilizar HTTPS y no incluir credenciales, consulta ni fragmento.'
}

$taskName = 'PanelDeployAgent'
$root = Join-Path $env:ProgramData 'PanelDeploy'
$source = Join-Path $PSScriptRoot 'PanelDeployAgent.ps1'
if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
    throw 'Copie PanelDeployAgent.ps1 junto al instalador.'
}
if ((Test-Path -LiteralPath $root) -or (Get-ScheduledTask -TaskPath '\' | Where-Object { $_.TaskName -eq $taskName })) {
    throw 'Ya existe C:\ProgramData\PanelDeploy o la tarea PanelDeployAgent. No se sobrescribió nada.'
}

$credential = Get-Credential -Message 'Cuenta Windows que ejecutará el agente (requiere acceso SSH y permiso de inicio como tarea por lotes)'
if ($null -eq $credential) { throw 'No se indicó la cuenta para la tarea.' }
$account = [Security.Principal.NTAccount]::new($credential.UserName)
$accountSid = $account.Translate([Security.Principal.SecurityIdentifier])
$secureToken = Read-Host 'Token del servidor generado en el panel' -AsSecureString
$token = [Net.NetworkCredential]::new('', $secureToken).Password
if ($token -cnotmatch '^[1-9][0-9]*\.[a-f0-9]{64}$') {
    throw 'El token no tiene el formato esperado.'
}

New-Item -ItemType Directory -Path $root | Out-Null
$acl = Get-Acl -LiteralPath $root
$acl.SetAccessRuleProtection($true, $false)
$inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
$propagation = [Security.AccessControl.PropagationFlags]::None
foreach ($sid in @($accountSid, [Security.Principal.SecurityIdentifier]::new('S-1-5-18'), [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544'))) {
    $rule = [Security.AccessControl.FileSystemAccessRule]::new(
        $sid, [Security.AccessControl.FileSystemRights]::FullControl,
        $inheritance, $propagation, [Security.AccessControl.AccessControlType]::Allow
    )
    $acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $root -AclObject $acl

$state = Join-Path $root 'estado'
New-Item -ItemType Directory -Path $state | Out-Null
Copy-Item -LiteralPath $source -Destination (Join-Path $root 'PanelDeployAgent.ps1')
$configPath = Join-Path $root 'config.json'
$config = @{ PanelUrl = $panelUri.AbsoluteUri.TrimEnd('/'); Token = $token; StateDirectory = $state }
[IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json), [Text.UTF8Encoding]::new($false))
$token = $null
$config = $null

$arguments = '-NoProfile -NonInteractive -File "{0}" -ConfigPath "{1}"' -f (Join-Path $root 'PanelDeployAgent.ps1'), $configPath
$action = New-ScheduledTaskAction -Execute (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') -Argument $arguments
$trigger = New-ScheduledTaskTrigger -Daily -At '00:00'
$repetition = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 1)
$trigger.Repetition = $repetition.Repetition
$settings = New-ScheduledTaskSettingsSet -Disable -MultipleInstances IgnoreNew -StartWhenAvailable
$password = $credential.GetNetworkCredential().Password
try {
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -User $credential.UserName -Password $password | Out-Null
} finally {
    $password = $null
    $credential = $null
}
Write-Output 'Instalación creada. La tarea está DESHABILITADA hasta completar las verificaciones.'
