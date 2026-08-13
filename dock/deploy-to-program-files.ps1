# =====================================================================
# Déploiement DockPolice (dock client) vers C:\Program Files\DockPolice
# Usage : powershell -ExecutionPolicy Bypass -File deploy-to-program-files.ps1
# Doit être exécuté en ADMINISTRATEUR.
# =====================================================================

[CmdletBinding()]
param(
    [string]$Target = "C:\Program Files\DockPolice",
    [string]$Source = "C:\pincile\DockLite\DockLite\bin\Release\net8.0-windows\win-x64\publish"
)

$ErrorActionPreference = "Stop"

# Vérification admin
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) {
    Write-Error "Ce script doit être exécuté en tant qu'administrateur."
    exit 1
}

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host " Déploiement DockPolice → $Target"          -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

if (-not (Test-Path $Source)) {
    Write-Error "Source introuvable : $Source. Lance d'abord :`n  cd C:\pincile\DockLite\DockLite`n  dotnet publish -c Release -r win-x64 --self-contained false"
    exit 1
}

# 1. Tue les processus DockPolice en cours
Write-Host ""
Write-Host "[1/4] Arrêt des instances DockPolice..." -ForegroundColor Yellow
$procs = Get-Process -Name "DockPolice" -ErrorAction SilentlyContinue
if ($procs) {
    $procs | Stop-Process -Force
    Write-Host "       $($procs.Count) instance(s) arrêtée(s)" -ForegroundColor Green
    Start-Sleep -Seconds 2
} else {
    Write-Host "       Aucune instance en cours" -ForegroundColor Gray
}

# 2. Sauvegarde de l'apps.json existant si présent (config admin)
$savedConfig = $null
$cfgPath = Join-Path $Target "apps.json"
if (Test-Path $cfgPath) {
    $savedConfig = Get-Content $cfgPath -Raw -ErrorAction SilentlyContinue
    Write-Host "[2/4] Sauvegarde de apps.json existant" -ForegroundColor Yellow
} else {
    Write-Host "[2/4] Pas d'apps.json à préserver" -ForegroundColor Gray
}

# 3. Copie des nouveaux fichiers
Write-Host "[3/4] Copie des nouveaux fichiers..." -ForegroundColor Yellow
if (-not (Test-Path $Target)) { New-Item -ItemType Directory -Path $Target -Force | Out-Null }
Copy-Item -Path "$Source\*" -Destination $Target -Recurse -Force
$count = (Get-ChildItem $Target -Recurse -File | Measure-Object).Count
Write-Host "       $count fichier(s) déployé(s)" -ForegroundColor Green

# 4. Restauration de la config si elle existait
if ($savedConfig) {
    Set-Content -Path $cfgPath -Value $savedConfig -Encoding UTF8
    Write-Host "[4/4] Configuration apps.json restaurée" -ForegroundColor Green
} else {
    Write-Host "[4/4] Pas de configuration à restaurer" -ForegroundColor Gray
}

Write-Host ""
Write-Host "==========================================" -ForegroundColor Green
Write-Host " ✓ Déploiement terminé"                     -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Cible      : $Target"
Write-Host "Exécutable : $Target\DockPolice.exe"
Write-Host ""
Write-Host "Prochaine étape :"
Write-Host "  - Lancer DockPolice.exe (ou attendre la prochaine session utilisateur)"
Write-Host "  - Les infos BIOS seront poussées au backoffice automatiquement"
