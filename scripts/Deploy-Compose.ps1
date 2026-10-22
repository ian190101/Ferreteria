param(
    [Parameter(Mandatory)][ValidatePattern('^[a-z0-9][a-z0-9_-]*$')][string]$Project,
    [Parameter(Mandatory)][string]$EnvironmentFile,
    [Parameter(Mandatory)][ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$')][string]$Version,
    [Parameter(Mandatory)][ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$')][string]$Revision,
    [switch]$Initial
)
$ErrorActionPreference = 'Stop'
$releaseRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$releaseEnvironment = (Resolve-Path -LiteralPath $EnvironmentFile).Path
$env:APP_ENV_FILE = $releaseEnvironment
$env:RELEASE_VERSION = $Version
$env:RELEASE_COMMIT = $Revision
$env:RELEASE_TIMESTAMP = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
$env:RELEASE_IMAGE = 'calmina-aroma:' + $Version + '-' + $Revision.Substring(0, [Math]::Min(24, $Revision.Length))
$composeArguments = @('compose', '--project-name', $Project, '--env-file', $releaseEnvironment, '-f', (Join-Path $releaseRoot 'docker-compose.local.yml'), '-f', (Join-Path $releaseRoot 'docker-compose.release.yml'))
$releaseStage = 'validación de configuración'
$previousImage = 'sin instalación previa'
$releaseTouched = $false

function Invoke-ReleaseDocker {
    param([Parameter(Mandatory)][string[]]$Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Docker falló en la etapa: $releaseStage (código $LASTEXITCODE)." }
}
function Invoke-ReleaseCompose {
    param([Parameter(Mandatory)][string[]]$Arguments)
    Invoke-ReleaseDocker -Arguments ($composeArguments + $Arguments)
}

Push-Location -LiteralPath $releaseRoot
try {
    Invoke-ReleaseDocker -Arguments @('info', '--format', '{{.ServerVersion}}') | Out-Null
    Invoke-ReleaseCompose -Arguments @('config', '--quiet')
    $currentContainer = (Invoke-ReleaseCompose -Arguments @('ps', '--all', '-q', 'app-ferreteria') | Out-String).Trim()
    if ($currentContainer) {
        if ($Initial) { throw 'No se permite -Initial sobre una instalación existente.' }
        $previousImage = (Invoke-ReleaseDocker -Arguments @('inspect', '--format', '{{.Image}}', $currentContainer) | Out-String).Trim()
    } else {
        if (-not $Initial) { throw 'La primera instalación requiere -Initial.' }
        & docker volume inspect ($Project + '_mysql_data') *> $null
        if ($LASTEXITCODE -eq 0) { throw 'Existe un volumen de base previo. Revisa respaldo y recuperación antes de iniciar una instalación.' }
    }

    $releaseStage = 'construcción de imagen'
    Invoke-ReleaseCompose -Arguments @('build', 'app-ferreteria')
    $imageId = (Invoke-ReleaseDocker -Arguments @('image', 'inspect', '--format', '{{.Id}}', $env:RELEASE_IMAGE) | Out-String).Trim()
    if ($currentContainer) {
        $releaseTouched = $true
        $releaseStage = 'detención de escritores y respaldo verificado'
        Invoke-ReleaseCompose -Arguments @('stop', 'queue-ferreteria', 'scheduler-ferreteria', 'maintenance-ferreteria')
        Invoke-ReleaseCompose -Arguments @('exec', '-T', 'app-ferreteria', 'php', 'artisan', 'down', '--retry=60')
        Invoke-ReleaseCompose -Arguments @('exec', '-T', 'app-ferreteria', 'php', 'artisan', 'production:pre-update', "--target=$Version")
    } else {
        $releaseStage = 'arranque inicial de base'
        Invoke-ReleaseCompose -Arguments @('up', '-d', '--wait', '--wait-timeout', '180', 'db-ferreteria')
    }
    $releaseStage = 'sustitución de aplicación y migraciones'
    $releaseTouched = $true
    Invoke-ReleaseCompose -Arguments @('up', '-d', '--no-deps', '--no-build', '--force-recreate', '--wait', '--wait-timeout', '1800', 'app-ferreteria')
    $releaseStage = 'verificación de publicación'
    Invoke-ReleaseCompose -Arguments @('exec', '-T', 'app-ferreteria', 'php', 'artisan', 'deployment:verify', "--expected-commit=$Revision")
    $activeContainer = (Invoke-ReleaseCompose -Arguments @('ps', '-q', 'app-ferreteria') | Out-String).Trim()
    $activeImage = (Invoke-ReleaseDocker -Arguments @('inspect', '--format', '{{.Image}}', $activeContainer) | Out-String).Trim()
    if ($activeImage -ne $imageId) { throw 'La aplicación no usa la imagen construida.' }
    $releaseStage = 'reinicio de procesos'
    Invoke-ReleaseCompose -Arguments @('up', '-d', '--no-deps', '--no-build', '--force-recreate', '--wait', '--wait-timeout', '120', 'queue-ferreteria', 'scheduler-ferreteria', 'maintenance-ferreteria')
    $ledgerDirectory = Join-Path $releaseRoot 'storage/app/private/deployments'
    New-Item -ItemType Directory -Path $ledgerDirectory -Force | Out-Null
    $ledger = @{ version = $Version; revision = $Revision; image = $imageId; previous_image = $previousImage; completed_at = $env:RELEASE_TIMESTAMP }
    $ledgerPath = Join-Path $ledgerDirectory ($Project + '-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss-fffffff') + '.json')
    $ledger | ConvertTo-Json | Set-Content -LiteralPath $ledgerPath -Encoding utf8
    Write-Output "Despliegue completado y verificado: $Version ($imageId)."
} catch {
    $releaseFailureMessage = $_.Exception.Message
    if ($releaseTouched) {
        try { & docker @composeArguments exec -T app-ferreteria php artisan down --retry=60 *> $null } catch { }
    }
    Write-Error "Despliegue incompleto. Etapa: $releaseStage. Imagen previa: $previousImage. Revisa respaldo y mantenimiento; no se afirma reversión de migraciones. $releaseFailureMessage"
    exit 1
} finally { Pop-Location }
