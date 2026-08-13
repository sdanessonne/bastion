# =====================================================================
# DockPolice Agent - Desinstallation
# =====================================================================
$ErrorActionPreference = "Continue"

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process powershell.exe -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`"" -Verb RunAs
    exit
}

$serviceName = "DockPoliceAgent"
$installDir = "C:\Program Files\DockPolice\Agent"

Write-Host "==> Arret et suppression du service..." -ForegroundColor Cyan
Stop-Service -Name $serviceName -Force -ErrorAction SilentlyContinue
& sc.exe delete $serviceName

Write-Host "==> Suppression des fichiers..." -ForegroundColor Cyan
if (Test-Path $installDir) {
    Start-Sleep -Seconds 1
    Remove-Item -Recurse -Force $installDir
}

Write-Host "[OK] DockPolice Agent desinstalle." -ForegroundColor Green
