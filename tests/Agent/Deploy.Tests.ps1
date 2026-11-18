param(
    [Parameter(Mandatory = $true)]
    [string] $TestRoot
)

$ErrorActionPreference = 'Stop'
$agentPath = Join-Path (Join-Path $PSScriptRoot '..\..') 'agent\PanelDeployAgent.ps1'
$tokens = $null
$errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($agentPath, [ref] $tokens, [ref] $errors)
if ($errors.Count -ne 0) { throw 'El script del agente contiene errores de sintaxis.' }
foreach ($name in @('Stop-Deployment', 'Invoke-Git', 'Test-Preserved', 'Test-IntersectsProtected', 'Assert-NoReparsePoint', 'Invoke-Deployment')) {
    $definition = $ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true) |
        Where-Object { $_.Name -eq $name } | Select-Object -First 1
    Invoke-Expression $definition.Extent.Text
}

# The fixture has all commits locally; only the network fetch is substituted.
$gitBinary = (Get-Command git -CommandType Application | Select-Object -First 1).Source
function git {
    if ($args -contains 'fetch') { $global:LASTEXITCODE = 0; return }
    & $gitBinary @args
}

function Assert($Condition, [string] $Message) {
    if (-not $Condition) { throw $Message }
}

function Run-Git([string[]] $Arguments) {
    & git @Arguments 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "No se pudo preparar el repositorio de prueba: $Arguments" }
}

$root = Join-Path $TestRoot ('panel-agent-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $root | Out-Null
Push-Location $root
try {
    Run-Git @('init', '-q')
    Run-Git @('config', 'user.email', 'agent-test@example.invalid')
    Run-Git @('config', 'user.name', 'Agent Test')
    Run-Git @('config', 'commit.gpgsign', 'false')
    Run-Git @('remote', 'add', 'origin', 'git@gitea.test:team/app.git')
    [IO.File]::WriteAllText((Join-Path $root 'app.php'), 'v1')
    [IO.File]::WriteAllText((Join-Path $root 'config.php'), 'default')
    [IO.File]::WriteAllText((Join-Path $root '.gitignore'), "ignored.txt`n")
    Run-Git @('add', '.')
    Run-Git @('commit', '-qm', 'base')
    $base = (& git rev-parse HEAD).Trim()
    [IO.File]::WriteAllText((Join-Path $root 'app.php'), 'v2')
    [IO.File]::WriteAllText((Join-Path $root 'ignored.txt'), 'versioned')
    Run-Git @('add', '.')
    Run-Git @('add', '-f', 'ignored.txt')
    Run-Git @('commit', '-qm', 'application')
    $target = (& git rev-parse HEAD).Trim()
    [IO.File]::WriteAllText((Join-Path $root 'config.php'), 'new default')
    Run-Git @('add', '.')
    Run-Git @('commit', '-qm', 'protected')
    $protectedTarget = (& git rev-parse HEAD).Trim()
    Run-Git @('checkout', '-q', '--detach', $base)
    [IO.File]::WriteAllText((Join-Path $root 'config.php'), 'local configuration')
    $job = @{ path = $root; repository_url = 'git@gitea.test:team/app.git'; commit = $target; preserved_paths = @('config.php') }
    Invoke-Deployment $job
    Assert ($env:GIT_SSH_COMMAND -eq 'ssh -o BatchMode=yes -o StrictHostKeyChecking=yes') 'No se exigió la verificación de identidad SSH.'
    Assert ((Get-Content -Raw (Join-Path $root 'app.php')) -eq 'v2') 'No se actualizó el archivo de la aplicación.'
    Assert ((Get-Content -Raw (Join-Path $root 'config.php')) -eq 'local configuration') 'Se modificó el archivo protegido.'
    $job.commit = $protectedTarget
    $blocked = $false
    try { Invoke-Deployment $job } catch [System.ApplicationException] { $blocked = $true }
    Assert $blocked 'No se bloqueó un commit que cambia la ruta protegida.'
    Assert ((& git rev-parse HEAD).Trim() -eq $target) 'El commit cambió a pesar del bloqueo.'
    [IO.File]::WriteAllText((Join-Path $root 'app.php'), 'local modification')
    $job.commit = $base
    $blocked = $false
    try { Invoke-Deployment $job } catch [System.ApplicationException] { $blocked = $true }
    Assert $blocked 'No se bloquearon los cambios locales fuera de las rutas protegidas.'
    Assert ((Get-Content -Raw (Join-Path $root 'app.php')) -eq 'local modification') 'Se sobrescribió un cambio local.'
    [IO.File]::WriteAllText((Join-Path $root 'app.php'), 'v2')
    Run-Git @('checkout', '-q', '--detach', $base)
    [IO.File]::WriteAllText((Join-Path $root 'ignored.txt'), 'local ignored file')
    $job.commit = $target
    $blocked = $false
    try { Invoke-Deployment $job } catch [System.ApplicationException] { $blocked = $true }
    Assert $blocked 'No se bloqueó un archivo local ignorado que sería sobrescrito.'
    Assert ((Get-Content -Raw (Join-Path $root 'ignored.txt')) -eq 'local ignored file') 'Se sobrescribió un archivo ignorado.'
    Remove-Item -LiteralPath (Join-Path $root 'ignored.txt')
    Run-Git @('update-index', '--skip-worktree', 'app.php')
    $blocked = $false
    try { Invoke-Deployment $job } catch [System.ApplicationException] { $blocked = $true }
    Assert $blocked 'No se bloqueó un archivo oculto al estado de Git.'
    Run-Git @('update-index', '--no-skip-worktree', 'app.php')
    Run-Git @('remote', 'set-url', 'origin', 'ssh://git@gitea.test:2222/team/app.git')
    $job.repository_url = 'ssh://git@gitea.test:2222/team/app.git'
    Invoke-Deployment $job
    Assert ((& git rev-parse HEAD).Trim() -eq $target) 'No se desplegó usando una URL SSH con puerto.'
    $job.repository_url = 'http://gitea.test/team/app.git'
    $blocked = $false
    try { Invoke-Deployment $job } catch [System.ApplicationException] { $blocked = $true }
    Assert $blocked 'No se bloqueó un remoto HTTP.'
    Write-Output 'Pruebas del agente: correctas.'
} finally {
    Pop-Location
    Remove-Item -LiteralPath $root -Recurse -Force
}
