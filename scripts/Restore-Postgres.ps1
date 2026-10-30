param(
    [Parameter(Mandatory = $true)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string] $BackupFile,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $TargetDatabase,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $ConfirmTargetDatabase
)

$ErrorActionPreference = "Stop"
if ($TargetDatabase -ne $ConfirmTargetDatabase) {
    throw "The confirmation database name must match the target database exactly."
}
if ($env:ERP_ALLOW_DESTRUCTIVE_RESTORE -ne "YES") {
    throw "Set ERP_ALLOW_DESTRUCTIVE_RESTORE=YES after confirming a maintenance window and an independently verified backup."
}

$pgRestore = Get-Command pg_restore -ErrorAction SilentlyContinue
if (-not $pgRestore) {
    throw "pg_restore was not found. Install PostgreSQL client tools and add them to PATH."
}

$resolvedBackup = (Resolve-Path -LiteralPath $BackupFile).Path
$manifestPath = "$resolvedBackup.sha256"
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw "The SHA-256 manifest is missing: $manifestPath"
}
$expectedHash = (Get-Content -LiteralPath $manifestPath -TotalCount 1).Split(" ")[0].Trim()
$actualHash = (Get-FileHash -LiteralPath $resolvedBackup -Algorithm SHA256).Hash
if ($expectedHash -ne $actualHash) {
    throw "Backup checksum verification failed. Restore was not started."
}

Write-Warning "The target database '$TargetDatabase' will be overwritten."
& $pgRestore.Source --clean --if-exists --exit-on-error --no-owner --no-acl --dbname $TargetDatabase $resolvedBackup
if ($LASTEXITCODE -ne 0) {
    throw "pg_restore failed with exit code $LASTEXITCODE."
}
Write-Output "Restore completed for database: $TargetDatabase"
