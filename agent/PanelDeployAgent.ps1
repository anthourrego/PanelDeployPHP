param(
    [Parameter(Mandatory = $true)]
    [string] $ConfigPath
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Stop-Deployment([string] $Message) {
    throw [System.ApplicationException]::new($Message)
}

function Invoke-Git([string] $Operation, [string[]] $Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git -c core.quotepath=false -c core.hooksPath=NUL -c core.fsmonitor=false -c submodule.recurse=false @Arguments 2>$null)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { Stop-Deployment "Git falló durante $Operation (código $code)." }
    return $output
}

function Test-Preserved([string] $Path, [string[]] $Protected) {
    $candidate = $Path.Replace('\', '/').TrimEnd('/')
    foreach ($item in $Protected) {
        $p = $item.TrimEnd('/')
        if ($candidate.Equals($p, [StringComparison]::OrdinalIgnoreCase) -or
            $candidate.StartsWith($p + '/', [StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
}

function Test-IntersectsProtected([string] $Path, [string[]] $Protected) {
    if (Test-Preserved $Path $Protected) { return $true }
    $candidate = $Path.TrimEnd('/')
    foreach ($item in $Protected) {
        if ($item.StartsWith($candidate + '/', [StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
}

function Assert-NoReparsePoint([string] $Root, [string] $RelativePath) {
    $current = $Root
    $rootItem = Get-Item -LiteralPath $current -Force
    if (($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        Stop-Deployment 'La carpeta del repositorio es un enlace o punto de reanálisis.'
    }
    foreach ($part in $RelativePath.Split('/')) {
        if ($part -eq '' -or $part -eq '.' -or $part -eq '..' -or $part.Contains('\')) {
            Stop-Deployment 'Git devolvió una ruta no segura.'
        }
        $current = Join-Path $current $part
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                Stop-Deployment 'Una ruta afectada contiene un enlace o punto de reanálisis.'
            }
        }
    }
}

function Invoke-Deployment($Job) {
    if ($Job.commit -notmatch '^[a-f0-9]{40}$') { Stop-Deployment 'El commit no es válido.' }
    $sshPattern = '^(?:ssh://[A-Za-z0-9_][A-Za-z0-9._-]*@(?:[A-Za-z0-9_][A-Za-z0-9._-]*|\[[0-9A-Fa-f:]+\])(?::[0-9]{1,5})?/|[A-Za-z0-9_][A-Za-z0-9._-]*@(?:[A-Za-z0-9_][A-Za-z0-9._-]*|\[[0-9A-Fa-f:]+\]):)[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git$'
    $isSsh = [string] $Job.repository_url -cmatch $sshPattern
    if (-not $isSsh) {
        $repoUri = $null
        if (-not [Uri]::TryCreate([string] $Job.repository_url, [UriKind]::Absolute, [ref] $repoUri) -or
            $repoUri.Scheme -ne 'https' -or $repoUri.UserInfo -or $repoUri.Query -or $repoUri.Fragment) {
            Stop-Deployment 'El repositorio debe utilizar SSH o HTTPS sin contraseñas en la URL.'
        }
    }
    $protected = @($Job.preserved_paths)
    foreach ($p in $protected) {
        if (-not ($p -is [string]) -or $p -eq '' -or $p.StartsWith('/') -or
            $p.Contains('\') -or $p -match '(^|/)(\.{1,2}|\.git|(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.[^/]*)?)(/|$)|//|/$|[. ](/|$)|[<>:"|?*\x00-\x1f]') {
            Stop-Deployment 'La lista de rutas protegidas no es válida.'
        }
    }
    $root = [IO.Path]::GetFullPath([string] $Job.path).TrimEnd('\')
    if (-not [IO.Path]::IsPathRooted($root) -or -not (Test-Path -LiteralPath $root -PathType Container)) {
        Stop-Deployment 'La carpeta del proyecto no existe o no es absoluta.'
    }
    # Check every existing ancestor; junctions above the repository are unsafe too.
    $ancestor = Get-Item -LiteralPath $root -Force
    while ($null -ne $ancestor) {
        if (($ancestor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            Stop-Deployment 'La ruta del repositorio contiene un punto de reanálisis.'
        }
        $ancestor = $ancestor.Parent
    }
    Push-Location -LiteralPath $root
    try {
        $gitRoot = @(Invoke-Git 'verificación de repositorio' @('rev-parse', '--show-toplevel'))
        if ($gitRoot.Count -ne 1 -or -not [IO.Path]::GetFullPath($gitRoot[0]).TrimEnd('\').Equals($root, [StringComparison]::OrdinalIgnoreCase)) {
            Stop-Deployment 'La ruta configurada no es la raíz del repositorio Git.'
        }
        $remotes = @(Invoke-Git 'verificación del remoto' @('remote', 'get-url', '--all', 'origin'))
        if ($remotes.Count -ne 1 -or -not $remotes[0].Equals([string] $Job.repository_url, [StringComparison]::Ordinal)) {
            Stop-Deployment 'El remoto origin no coincide exactamente con el repositorio configurado.'
        }
        foreach ($marker in @('MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'BISECT_LOG')) {
            $markerPath = @(Invoke-Git 'verificación del estado de Git' @('rev-parse', '--git-path', $marker))
            if ($markerPath.Count -ne 1 -or (Test-Path -LiteralPath $markerPath[0])) {
                Stop-Deployment 'Hay una operación Git en curso.'
            }
        }
        $env:GIT_TERMINAL_PROMPT = '0'
        $env:GCM_INTERACTIVE = 'Never'
        if ($isSsh) {
            $env:GIT_SSH_COMMAND = 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes'
            $env:GIT_SSH_VARIANT = 'ssh'
        }
        Invoke-Git 'descarga del remoto' @('fetch', '--no-tags', 'origin') | Out-Null
        Invoke-Git 'verificación del commit' @('cat-file', '-e', ($Job.commit + '^{commit}')) | Out-Null
        foreach ($entry in @(Invoke-Git 'verificación del índice' @('ls-files', '-v'))) {
            if ($entry -cmatch '^([a-z]|S) ') {
                Stop-Deployment 'El índice contiene archivos ocultos a la verificación de cambios.'
            }
        }
        foreach ($treeRef in @('HEAD', $Job.commit)) {
            foreach ($entry in @(Invoke-Git 'verificación del árbol' @('ls-tree', '-r', '--full-tree', $treeRef))) {
                if ($entry -match '^(120000|160000) ' -or $entry -match "`t`"") {
                    Stop-Deployment 'El repositorio contiene enlaces, submódulos o rutas no compatibles.'
                }
            }
        }
        $changed = @(Invoke-Git 'comparación del commit' @('diff', '--name-only', '--no-renames', 'HEAD', $Job.commit, '--'))
        foreach ($path in $changed) {
            if ($path.StartsWith('"')) { Stop-Deployment 'El commit contiene una ruta no compatible.' }
            if (Test-IntersectsProtected $path $protected) {
                Stop-Deployment 'El commit cambia una ruta protegida; requiere revisión manual.'
            }
            Assert-NoReparsePoint $root $path
        }
        $status = @(Invoke-Git 'comprobación de cambios locales' @('status', '--porcelain=v1', '--untracked-files=all', '--ignored=matching'))
        foreach ($entry in $status) {
            if ($entry.Length -lt 4 -or $entry.Substring(0, 2) -match '[RCU]' -or $entry.Substring(0, 2) -in @('AA', 'DD') -or $entry.Substring(3).StartsWith('"')) {
                Stop-Deployment 'El estado de Git contiene cambios o rutas que no se pueden verificar.'
            }
            if (-not (Test-Preserved $entry.Substring(3) $protected)) {
                Stop-Deployment 'Se encontraron cambios o archivos locales fuera de las rutas protegidas.'
            }
        }
        $script:checkoutStarted = $true
        Invoke-Git 'cambio de versión' @('checkout', '--detach', $Job.commit) | Out-Null
        $actual = @(Invoke-Git 'verificación final' @('rev-parse', 'HEAD'))
        if ($actual.Count -ne 1 -or $actual[0] -cne $Job.commit) {
            Stop-Deployment 'No se pudo verificar el commit desplegado.'
        }
        $script:checkoutStarted = $false
    } finally {
        Pop-Location
    }
}

function Send-Report($Config, $Report) {
    $headers = @{ Authorization = 'Bearer ' + $Config.Token }
    $body = @{ status = $Report.status; result = $Report.result } | ConvertTo-Json -Compress
    $response = Invoke-RestMethod -Method Post -Uri ($Config.PanelUrl.TrimEnd('/') + '/api/agent/targets/' + $Report.target_id + '/report') -Headers $headers -ContentType 'application/json' -Body $body -MaximumRedirection 0 -TimeoutSec 30
    if ($response.state -ne 'recorded') { throw 'El panel no confirmó el resultado.' }
}

$mutex = [Threading.Mutex]::new($false, 'Global\PanelDeployAgent')
$acquired = $false
$script:checkoutStarted = $false
try {
    try { $acquired = $mutex.WaitOne(0) } catch [Threading.AbandonedMutexException] { $acquired = $true }
    if (-not $acquired) { throw 'Ya hay un agente en ejecución en este servidor.' }
    $config = Get-Content -LiteralPath $ConfigPath -Raw | ConvertFrom-Json
    $panelUri = $null
    if (-not [Uri]::TryCreate([string] $config.PanelUrl, [UriKind]::Absolute, [ref] $panelUri) -or
        $panelUri.Scheme -ne 'https' -or $panelUri.UserInfo -or $panelUri.Query -or $panelUri.Fragment -or
        $config.Token -notmatch '^[1-9][0-9]*\.[a-f0-9]{64}$') {
        throw 'Configuración de panel o token inválida; se requiere HTTPS.'
    }
    if (-not (Test-Path -LiteralPath $config.StateDirectory -PathType Container)) {
        throw 'El directorio privado de estado no existe.'
    }
    $journal = Join-Path $config.StateDirectory 'pending-report.json'
    if (Test-Path -LiteralPath $journal) {
        $report = Get-Content -LiteralPath $journal -Raw | ConvertFrom-Json
        Send-Report $config $report
        Remove-Item -LiteralPath $journal
        Write-Output 'Resultado pendiente entregado.'
        return
    }
    $headers = @{ Authorization = 'Bearer ' + $config.Token }
    $job = Invoke-RestMethod -Method Post -Uri ($config.PanelUrl.TrimEnd('/') + '/api/agent/claim') -Headers $headers -MaximumRedirection 0 -TimeoutSec 30
    if ($job.state -eq 'idle') { Write-Output 'Sin trabajos pendientes.'; return }
    if ($job.state -eq 'busy') { Write-Warning 'Existe un trabajo activo; no se volverá a ejecutar automáticamente.'; return }
    if ($job.state -ne 'claimed' -or $job.target_id -notmatch '^[1-9][0-9]*$') { throw 'Respuesta de asignación inválida.' }
    try {
        Invoke-Deployment $job
        $report = @{ target_id = $job.target_id; status = 'succeeded'; result = 'Commit verificado y desplegado.' }
    } catch {
        $message = 'Error inesperado durante el despliegue. Revise el servidor.'
        if ($_.Exception -is [System.ApplicationException]) { $message = $_.Exception.Message }
        $status = 'failed'
        if ($script:checkoutStarted) {
            $status = 'uncertain'
            $message = 'El cambio de versión pudo quedar incompleto; requiere revisión manual.'
        }
        $report = @{ target_id = $job.target_id; status = $status; result = $message }
    }
    $temporary = $journal + '.tmp'
    [IO.File]::WriteAllText($temporary, ($report | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $journal
    Send-Report $config $report
    Remove-Item -LiteralPath $journal
    Write-Output "Resultado registrado: $($report.status)."
} catch {
    Write-Error 'El agente no pudo completar la comunicación o guardar el resultado; revise el servidor y el panel.'
    exit 1
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
