# =====================================================================
# DockPolice Agent - Installation du service Windows
# =====================================================================
# Auto-elevation en admin.
# Publie + installe le service DockPoliceAgent qui tourne en SYSTEM.
#
# Usage :
#   powershell -ExecutionPolicy Bypass -File .\install-agent.ps1
# =====================================================================

$ErrorActionPreference = "Stop"

# Auto-elevation
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Privileges admin requis. Relancement..." -ForegroundColor Yellow
    Start-Process powershell.exe -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    exit
}

$projectDir = $PSScriptRoot
$installDir = "C:\Program Files\DockPolice\Agent"
$serviceName = "DockPoliceAgent"
$displayName = "DockPolice Agent"
$description = "Telemetry agent + remote command runner pour DockPolice"

Write-Host ""
Write-Host "==> Build et publication de l'agent..." -ForegroundColor Cyan
$publishDir = Join-Path $projectDir "bin\Publish"
if (Test-Path $publishDir) { Remove-Item -Recurse -Force $publishDir }

dotnet publish "$projectDir\DockPolice.Agent.csproj" `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -o $publishDir

if ($LASTEXITCODE -ne 0) {
    Write-Host "[X] Echec publish" -ForegroundColor Red
    exit 1
}

# Copie du agent.json (pas inclus dans single-file)
Copy-Item (Join-Path $projectDir "agent.json") (Join-Path $publishDir "agent.json") -Force

Write-Host ""
Write-Host "==> Installation dans $installDir..." -ForegroundColor Cyan

# Stop si deja installe
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "    Service existant detecte, arret..." -ForegroundColor Yellow
    Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue
    & sc.exe delete $serviceName | Out-Null
    Start-Sleep -Seconds 2
}

if (-not (Test-Path $installDir)) {
    New-Item -ItemType Directory -Path $installDir -Force | Out-Null
}
Copy-Item -Path "$publishDir\*" -Destination $installDir -Recurse -Force

$exePath = Join-Path $installDir "DockPolice.Agent.exe"

Write-Host "==> Creation du service Windows..." -ForegroundColor Cyan
& sc.exe create $serviceName binPath= "`"$exePath`"" start= auto DisplayName= $displayName | Out-Null
& sc.exe description $serviceName $description | Out-Null

# Recovery : redemarre auto en cas de plantage
& sc.exe failure $serviceName reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null

Write-Host "==> Demarrage du service..." -ForegroundColor Cyan
Start-Service -Name $serviceName

Start-Sleep -Seconds 2
$svc = Get-Service -Name $serviceName
Write-Host ""
Write-Host "[OK] Service installe." -ForegroundColor Green
Write-Host "  Nom    : $serviceName" -ForegroundColor Green
Write-Host "  Statut : $($svc.Status)" -ForegroundColor Green
Write-Host "  Demarrage : $((Get-WmiObject Win32_Service -Filter "Name='$serviceName'").StartMode)" -ForegroundColor Green
Write-Host "  Compte : $((Get-WmiObject Win32_Service -Filter "Name='$serviceName'").StartName)" -ForegroundColor Green
Write-Host ""
Write-Host "Logs : Observateur d'evenements -> Journaux Windows -> Application -> source 'DockPolice Agent'" -ForegroundColor Yellow

# Si DockPolice (UI) est deja installe avec un raccourci Startup, on le retire :
# le service va maintenant prendre le relais pour le lancement.
$shortcutRun = Join-Path ([Environment]::GetFolderPath("CommonStartup")) "DockPolice.lnk"
if (Test-Path $shortcutRun) {
    Write-Host ""
    Write-Host "==> Retrait du raccourci Startup (le service prend le relais)..." -ForegroundColor Cyan
    Remove-Item $shortcutRun -Force -ErrorAction SilentlyContinue
}

# Lance le dock dans la session courante immediatement (sans attendre un re-login)
$dockExe = Join-Path ([Environment]::GetFolderPath("ProgramFiles")) "DockPolice\DockPolice.exe"
if (Test-Path $dockExe) {
    Write-Host "==> Lancement du dock dans la session courante..." -ForegroundColor Cyan
    # Le service se chargera de le relancer au prochain logon
    Start-Process -FilePath $dockExe
}
