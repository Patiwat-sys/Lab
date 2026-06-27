# Install repo git hooks (strips Co-authored-by: Cursor from commits).
$repoRoot = Split-Path -Parent $PSScriptRoot
$hooksSrc = Join-Path $repoRoot ".githooks"
$hooksDst = Join-Path $repoRoot ".git\hooks"

if (-not (Test-Path $hooksSrc)) {
    Write-Error ".githooks folder not found."
    exit 1
}

Get-ChildItem $hooksSrc -File | ForEach-Object {
    $dest = Join-Path $hooksDst $_.Name
    Copy-Item $_.FullName $dest -Force
    Write-Host "Installed hook: $($_.Name)"
}

Write-Host "Done. Future commits will not keep Co-authored-by: Cursor."
