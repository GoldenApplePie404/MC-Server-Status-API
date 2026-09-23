# ============================================================
# mcmonitor release builder (Windows)
# Usage: ./build-release.ps1                # default v0.4.0
#        ./build-release.ps1 -Version 0.5.0
#        ./build-release.ps1 -SkipBuild     # skip go build, just zip
# ============================================================

param(
    [string]$Version = "0.4.0",
    [switch]$SkipBuild
)

$ErrorActionPreference = "Stop"
$clientDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$rootDir   = Split-Path -Parent $clientDir
$outDir    = Join-Path $rootDir "release\mcmonitor-v$Version-windows-amd64"
$zipPath   = Join-Path $rootDir "release\mcmonitor-v$Version-windows-amd64.zip"

Write-Host "==> mcmonitor v$Version release build" -ForegroundColor Cyan
Write-Host "    output: $outDir"
Write-Host ""

# Clean
if (Test-Path $outDir) { Remove-Item $outDir -Recurse -Force }
New-Item -ItemType Directory -Path $outDir -Force | Out-Null

# ---------- 1. Build ----------
if (-not $SkipBuild) {
    Write-Host "==> go build (trimpath + strip) ..." -ForegroundColor Yellow
    Push-Location $clientDir
    go vet ./...
    if ($LASTEXITCODE -ne 0) { Pop-Location; throw "go vet failed" }
    go build -trimpath -ldflags "-s -w -X main.Version=$Version" -o mcmonitor.exe .
    if ($LASTEXITCODE -ne 0) { Pop-Location; throw "go build failed" }
    Pop-Location
    $size = [math]::Round((Get-Item "$clientDir\mcmonitor.exe").Length / 1MB, 1)
    Write-Host "    built: mcmonitor.exe ($size MB)"
}
else {
    Write-Host "==> skip build (use existing mcmonitor.exe)" -ForegroundColor Yellow
    if (-not (Test-Path "$clientDir\mcmonitor.exe")) { throw "mcmonitor.exe not found" }
}

# ---------- 2. Stage ----------
Write-Host "==> stage files ..." -ForegroundColor Yellow

Copy-Item "$clientDir\mcmonitor.exe"                 (Join-Path $outDir "mcmonitor.exe")                 -Force
Copy-Item "$clientDir\config.example.json"       (Join-Path $outDir "config.example.json")       -Force
Copy-Item "$clientDir\subscriptions.json.example" (Join-Path $outDir "subscriptions.json.example") -Force

# ---------- 3. Copy readme template (UTF-8 BOM, notepad-friendly on Chinese Windows) ----------
$tplPath = Join-Path $clientDir "release-readme.txt"
if (Test-Path $tplPath) {
    # Read as UTF-8 (no BOM) then write with BOM so Windows notepad displays Chinese correctly
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $utf8Bom   = New-Object System.Text.UTF8Encoding($true)
    $text = [System.IO.File]::ReadAllText($tplPath, $utf8NoBom)
    [System.IO.File]::WriteAllText((Join-Path $outDir "使用说明.txt"), $text, $utf8Bom)
}
else {
    Write-Host "    [skip] release-readme.txt not found" -ForegroundColor DarkGray
}

# ---------- 4. Zip (use .NET ZipFile, avoids file-lock issues with running exe) ----------
Write-Host "==> zip ..." -ForegroundColor Yellow
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($outDir, $zipPath, [System.IO.Compression.CompressionLevel]::Optimal, $false)

$zipSize = [math]::Round((Get-Item $zipPath).Length / 1MB, 1)
Write-Host ""
Write-Host "==> Done!" -ForegroundColor Green
Write-Host "    folder: $outDir"
Write-Host "    zip:    $zipPath ($zipSize MB)"
Write-Host ""
Write-Host "    To share: send the zip, recipient unzips and double-clicks mcmonitor.exe"
