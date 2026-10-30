param(
    [switch] $RunRecoveryTest
)

$ErrorActionPreference = "Stop"
if (-not $RunRecoveryTest) {
    throw "This creates two disposable PostgreSQL databases. Re-run with -RunRecoveryTest only after reviewing the script and confirming the configured PostgreSQL account may create/drop databases."
}

$createdb = Get-Command createdb -ErrorAction SilentlyContinue
$dropdb = Get-Command dropdb -ErrorAction SilentlyContinue
$pgDump = Get-Command pg_dump -ErrorAction SilentlyContinue
$pgRestore = Get-Command pg_restore -ErrorAction SilentlyContinue
if (-not $createdb -or -not $dropdb -or -not $pgDump -or -not $pgRestore) {
    throw "PostgreSQL client tools createdb, dropdb, pg_dump, and pg_restore must be installed and on PATH."
}

$databaseSuffix = [Guid]::NewGuid().ToString("N")
$sourceDatabase = "erp_recovery_source_$databaseSuffix"
$targetDatabase = "erp_recovery_target_$databaseSuffix"
$backupPath = Join-Path ([System.IO.Path]::GetTempPath()) "$sourceDatabase.dump"
$originalDatabase = $env:DB_DATABASE
$originalPgDatabase = $env:PGDATABASE
$createdSource = $false
$createdTarget = $false

try {
    & $createdb.Source --maintenance-db postgres $sourceDatabase
    if ($LASTEXITCODE -ne 0) { throw "Could not create disposable source database." }
    $createdSource = $true

    $env:DB_DATABASE = $sourceDatabase
    php (Join-Path $PSScriptRoot "..\api\artisan") migrate --force
    if ($LASTEXITCODE -ne 0) { throw "Migrations failed on the disposable source database." }
    php (Join-Path $PSScriptRoot "..\api\artisan") db:seed --class=AccessControlSeeder --force
    if ($LASTEXITCODE -ne 0) { throw "Permission seeding failed on the disposable source database." }

    & $pgDump.Source --format=custom --no-owner --no-acl --file $backupPath $sourceDatabase
    if ($LASTEXITCODE -ne 0) { throw "Backup of the disposable source database failed." }

    & $createdb.Source --maintenance-db postgres $targetDatabase
    if ($LASTEXITCODE -ne 0) { throw "Could not create disposable target database." }
    $createdTarget = $true
    & $pgRestore.Source --exit-on-error --no-owner --no-acl --dbname $targetDatabase $backupPath
    if ($LASTEXITCODE -ne 0) { throw "Restore to the disposable target database failed." }

    $env:DB_DATABASE = $targetDatabase
    $migrationStatus = php (Join-Path $PSScriptRoot "..\api\artisan") migrate:status
    if ($LASTEXITCODE -ne 0 -or ($migrationStatus -match "Pending")) {
        throw "The restored database has pending migrations or could not be inspected."
    }

    $env:PGDATABASE = $targetDatabase
    $migrationCount = (& psql --no-psqlrc --tuples-only --no-align --command "SELECT COUNT(*) FROM migrations;").Trim()
    if ($LASTEXITCODE -ne 0 -or [int]$migrationCount -lt 1) {
        throw "Restored migration metadata could not be verified."
    }

    Write-Output "Disposable PostgreSQL backup/restore test passed; verified $migrationCount migrations."
}
finally {
    if ($originalDatabase) { $env:DB_DATABASE = $originalDatabase } else { Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue }
    if ($originalPgDatabase) { $env:PGDATABASE = $originalPgDatabase } else { Remove-Item Env:PGDATABASE -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
    if ($createdTarget) {
        & $dropdb.Source --if-exists --maintenance-db postgres $targetDatabase
        if ($LASTEXITCODE -ne 0) { Write-Warning "Could not remove disposable target database $targetDatabase." }
    }
    if ($createdSource) {
        & $dropdb.Source --if-exists --maintenance-db postgres $sourceDatabase
        if ($LASTEXITCODE -ne 0) { Write-Warning "Could not remove disposable source database $sourceDatabase." }
    }
}
