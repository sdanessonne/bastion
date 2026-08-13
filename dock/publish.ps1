# =====================================================================
# DockPolice - script de packaging single-file (Windows x64)
# =====================================================================
#
# Usage : depuis PowerShell, dans le dossier C:\pincile\DockLite\
#   powershell -ExecutionPolicy Bypass -File .\publish.ps1
#
# Produit un executable autonome dans C:\pincile\dist\DockPolice\
# (ne necessite pas que .NET soit installe sur le poste cible).
# =====================================================================

$ErrorActionPreference = "Stop"

$projectDir = $PSScriptRoot
$outputDir  = Join-Path $projectDir "..\dist\DockPolice"
$outputDir  = [System.IO.Path]::GetFullPath($outputDir)

Write-Host "==> Nettoyage de $outputDir" -ForegroundColor Cyan
if (Test-Path $outputDir) {
    Remove-Item -Recurse -Force $outputDir
}
New-Item -ItemType Directory -Path $outputDir | Out-Null

Write-Host "==> Publication single-file (Release, win-x64, self-contained)" -ForegroundColor Cyan
dotnet publish "$projectDir\DockLite\DockLite.csproj" `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=embedded `
    -o $outputDir

Write-Host "==> Copie des fichiers de configuration" -ForegroundColor Cyan
$appsJsonSource = Join-Path $projectDir "DockLite\bin\Debug\net8.0-windows\apps.json"
if (Test-Path $appsJsonSource) {
    Copy-Item $appsJsonSource (Join-Path $outputDir "apps.json")
}

Write-Host "==> Copie des scripts d'installation" -ForegroundColor Cyan
Copy-Item (Join-Path $projectDir "tools\install-dockpolice.ps1")   $outputDir
Copy-Item (Join-Path $projectDir "tools\uninstall-dockpolice.ps1") $outputDir

# Certificat public (pour que l'installeur puisse l'importer dans Trusted Root)
$cerSource = Join-Path $projectDir "tools\DockPolice-CodeSign.cer"
if (Test-Path $cerSource) {
    Copy-Item $cerSource $outputDir
    Write-Host "    Certificat public inclus dans la distribution." -ForegroundColor Green
}

# Calcul taille
$exePath = Join-Path $outputDir "DockPolice.exe"
$sizeMb  = [math]::Round((Get-Item $exePath).Length / 1MB, 1)

Write-Host ""
Write-Host "[OK] Packaging termine." -ForegroundColor Green
$sizeText = "$sizeMb Mo"
Write-Host "  Executable : $exePath ($sizeText)" -ForegroundColor Green
Write-Host "  Dossier    : $outputDir" -ForegroundColor Green
Write-Host ""

# Signature automatique si le certificat existe
$pfxPath = Join-Path $projectDir "tools\DockPolice-CodeSign.pfx"
if (Test-Path $pfxPath) {
    Write-Host "==> Certificat trouve, signature de l'executable..." -ForegroundColor Cyan
    & (Join-Path $projectDir "tools\sign.ps1") -Path $exePath -PfxPath $pfxPath
} else {
    Write-Host "(Pas de certificat - exe non signe. Lance .\tools\new-cert.ps1 pour en creer un.)" -ForegroundColor Yellow
}

Write-Host ""

# Build MSI si WiX disponible
if (Get-Command wix -ErrorAction SilentlyContinue) {
    Write-Host "==> Generation du MSI..." -ForegroundColor Cyan
    & (Join-Path $projectDir "installer\build-msi.ps1")
} else {
    Write-Host "(WiX non installe - MSI non genere. Installer : dotnet tool install --global wix)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Distribution :" -ForegroundColor Yellow
Write-Host "  Option A (MSI - recommande) :" -ForegroundColor Yellow
Write-Host "    Copier dist\DockPolice-1.0.0.msi sur les postes" -ForegroundColor Yellow
Write-Host "    Double-clic ou : msiexec /i DockPolice-1.0.0.msi /qn" -ForegroundColor Yellow
Write-Host ""
Write-Host "  Option B (PowerShell) :" -ForegroundColor Yellow
Write-Host "    Copier dist\DockPolice\ entier" -ForegroundColor Yellow
Write-Host "    Lancer install-dockpolice.ps1 sur chaque poste" -ForegroundColor Yellow
