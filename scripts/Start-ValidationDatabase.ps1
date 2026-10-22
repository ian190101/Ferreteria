param([string] $MysqlBin = 'C:\xampp\mysql\bin')

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$qaRoot = Join-Path $projectRoot 'storage\qa'
$dataDirectory = Join-Path $qaRoot 'mysql'
$mysql = Join-Path $MysqlBin 'mysql.exe'
$server = Join-Path $MysqlBin 'mysqld.exe'
$installer = Join-Path $MysqlBin 'mysql_install_db.exe'

foreach ($binary in @($mysql, $server, $installer)) {
    if (-not (Test-Path -LiteralPath $binary)) { throw "Falta el ejecutable requerido: $binary" }
}
New-Item -ItemType Directory -Path $qaRoot -Force | Out-Null

$clientArguments = @('--connect-timeout=1', '--host=127.0.0.1', '--port=3307', '--user=root')
$currentDataDirectory = & $mysql @clientArguments '--batch' '--skip-column-names' '-e' 'SELECT @@datadir' 2>$null
$ready = $LASTEXITCODE -eq 0
$qaProcess = $null

if (-not $ready) {
    if (-not (Test-Path -LiteralPath (Join-Path $dataDirectory 'my.ini'))) {
        & $installer "--datadir=$dataDirectory" '--port=3307'
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo inicializar la base aislada.' }
    }

    # La instancia de QA conserva su configuración y sus datos fuera de XAMPP.
    $qaProcess = Start-Process -FilePath $server -ArgumentList @(
        "--defaults-file=`"$dataDirectory\my.ini`"", '--bind-address=127.0.0.1',
        '--explicit-defaults-for-timestamp=1', '--console'
    ) -WindowStyle Hidden -RedirectStandardOutput (Join-Path $qaRoot 'mysql-stdout.log') `
        -RedirectStandardError (Join-Path $qaRoot 'mysql-stderr.log') -PassThru

    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if ($qaProcess.HasExited) { throw 'La instancia no arrancó; revise storage/qa/mysql-stderr.log y el puerto 3307.' }
        $currentDataDirectory = & $mysql @clientArguments '--batch' '--skip-column-names' '-e' 'SELECT @@datadir' 2>$null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 500
    }
}
if (-not $ready) { throw 'La instancia aislada no respondió. Ejecute este script fuera del sandbox y revise storage/qa/mysql-stderr.log.' }

$expectedDataDirectory = [IO.Path]::GetFullPath($dataDirectory).TrimEnd('\', '/')
$actualDataDirectory = [IO.Path]::GetFullPath(([string] $currentDataDirectory).Trim()).TrimEnd('\', '/')
if ($actualDataDirectory -ne $expectedDataDirectory) {
    throw "El puerto 3307 pertenece a otra instancia: $actualDataDirectory"
}

$sql = @"
CREATE DATABASE IF NOT EXISTS fabrica_calmina_aroma_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'calmina_test'@'127.0.0.1' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON fabrica_calmina_aroma_testing.* TO 'calmina_test'@'127.0.0.1';
GRANT ALL PRIVILEGES ON ``sandbox\_%``.* TO 'calmina_test'@'127.0.0.1';
CREATE DATABASE IF NOT EXISTS fabrica_calmina_aroma_testing_restore_verification CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'calmina_restore'@'127.0.0.1' IDENTIFIED BY '';
GRANT ALL PRIVILEGES ON fabrica_calmina_aroma_testing_restore_verification.* TO 'calmina_restore'@'127.0.0.1';
SELECT VERSION();
"@
& $mysql @clientArguments '-e' $sql
if ($LASTEXITCODE -ne 0) { throw 'Falló la preparación de la base o del usuario restringido.' }
if ($null -ne $qaProcess) {
    Write-Output "Base de validación disponible en 127.0.0.1:3307 (PID $($qaProcess.Id))."
} else {
    Write-Output 'Base de validación existente disponible en 127.0.0.1:3307.'
}
