param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string] $Database,

    [string] $Destination = (Join-Path $PSScriptRoot "..\api\storage\app\private-backups")
)

$ErrorActionPreference = "Stop"
$pgDump = Get-Command pg_dump -ErrorAction SilentlyContinue
if (-not $pgDump) {
    throw "pg_dump was not found. Install PostgreSQL client tools and add them to PATH."
}

$resolvedDestination = [System.IO.Path]::GetFullPath($Destination)
New-Item -ItemType Directory -Path $resolvedDestination -Force | Out-Null
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupPath = Join-Path $resolvedDestination "$Database-$timestamp.dump"

& $pgDump.Source --format=custom --no-owner --no-acl --file $backupPath $Database
if ($LASTEXITCODE -ne 0) {
    Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
    throw "pg_dump failed with exit code $LASTEXITCODE."
}

$hash = Get-FileHash -LiteralPath $backupPath -Algorithm SHA256
$manifestPath = "$backupPath.sha256"
Set-Content -LiteralPath $manifestPath -Value "$($hash.Hash)  $(Split-Path -Leaf $backupPath)" -Encoding ascii
Write-Output "Backup created: $backupPath"
Write-Output "SHA-256 manifest: $manifestPath"
Write-Warning "Protect and encrypt this backup and its off-site copy. It contains sensitive school data."
