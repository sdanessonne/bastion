# =====================================================================
# DockPolice - Build du MSI avec WiX 6
# =====================================================================
# Prerequis :
#   - WiX 6 installe : dotnet tool install --global wix
#   - publish.ps1 a deja produit le dossier dist\DockPolice avec
#     DockPolice.exe, apps.json et DockPolice-CodeSign.cer
#
# Usage :
#   powershell -ExecutionPolicy Bypass -File .\installer\build-msi.ps1
# =====================================================================

$ErrorActionPreference = "Stop"

$installerDir = $PSScriptRoot
$projectDir   = Split-Path -Parent $installerDir
$distDir      = Join-Path $projectDir "..\dist\DockPolice"
$distDir      = [System.IO.Path]::GetFullPath($distDir)
$msiOutput    = Join-Path $distDir "..\DockPolice-1.0.0.msi"
$msiOutput    = [System.IO.Path]::GetFullPath($msiOutput)

# Verifications
if (-not (Test-Path (Join-Path $distDir "DockPolice.exe"))) {
    Write-Host "[X] DockPolice.exe introuvable dans $distDir" -ForegroundColor Red
    Write-Host "    Lance d'abord : .\publish.ps1" -ForegroundColor Yellow
    exit 1
}

if (-not (Test-Path (Join-Path $distDir "DockPolice-CodeSign.cer"))) {
    Write-Host "[X] DockPolice-CodeSign.cer introuvable dans $distDir" -ForegroundColor Red
    Write-Host "    Lance d'abord : .\tools\new-cert.ps1 puis .\publish.ps1" -ForegroundColor Yellow
    exit 1
}

if (-not (Get-Command wix -ErrorAction SilentlyContinue)) {
    Write-Host "[X] WiX introuvable. Installe-le : dotnet tool install --global wix" -ForegroundColor Red
    exit 1
}

Write-Host "==> Source dist : $distDir" -ForegroundColor Cyan
Write-Host "==> Sortie MSI  : $msiOutput" -ForegroundColor Cyan

# Verifier l'extension Util (necessaire pour les custom actions WixQuietExec)
Write-Host "==> Verification de l'extension WiX Util..." -ForegroundColor Cyan
$utilExt = wix extension list --global 2>&1 | Select-String "WixToolset.Util.wixext"
if (-not $utilExt) {
    Write-Host "    Installation de l'extension Util..." -ForegroundColor Yellow
    wix extension add --global WixToolset.Util.wixext
}

# Build
Push-Location $installerDir
try {
    Write-Host "==> Compilation WiX..." -ForegroundColor Cyan
    wix build "DockPolice.wxs" `
        -arch x64 `
        -d "DistDir=$distDir" `
        -ext WixToolset.Util.wixext `
        -o $msiOutput

    if ($LASTEXITCODE -ne 0) {
        Write-Host "[X] Echec de compilation WiX." -ForegroundColor Red
        exit 1
    }
} finally {
    Pop-Location
}

$sizeMb = [math]::Round((Get-Item $msiOutput).Length / 1MB, 1)
Write-Host ""
Write-Host "[OK] MSI genere." -ForegroundColor Green
Write-Host "  Fichier : $msiOutput ($sizeMb Mo)" -ForegroundColor Green
Write-Host ""

# Signature du MSI (si certificat present)
$pfxPath = Join-Path $projectDir "tools\DockPolice-CodeSign.pfx"
if (Test-Path $pfxPath) {
    Write-Host "==> Signature du MSI..." -ForegroundColor Cyan
    & (Join-Path $projectDir "tools\sign.ps1") -Path $msiOutput -PfxPath $pfxPath
} else {
    Write-Host "(MSI non signe - pas de certificat)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Distribution :" -ForegroundColor Yellow
Write-Host "  Copier $msiOutput sur les postes" -ForegroundColor Yellow
Write-Host "  Double-clic = installation interactive (UAC)" -ForegroundColor Yellow
Write-Host "  msiexec /i DockPolice-1.0.0.msi /qn = installation silencieuse" -ForegroundColor Yellow
Write-Host "  msiexec /x DockPolice-1.0.0.msi /qn = desinstallation silencieuse" -ForegroundColor Yellow
