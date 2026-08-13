# =====================================================================
# DockPolice - Desinstalleur
# =====================================================================
# - Arrete le processus
# - Retire le certificat des magasins
# - Supprime les raccourcis
# - Supprime les entrees registre
# - Supprime le dossier d'installation
#
# Auto-elevation en admin.
# =====================================================================

$ErrorActionPreference = "Continue"

# --- Auto-elevation ---
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $args = "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`""
    Start-Process powershell.exe -ArgumentList $args -Verb RunAs
    exit
}

$installDir   = "C:\Program Files\DockPolice"
$shortcutAll  = Join-Path ([Environment]::GetFolderPath("CommonStartMenu")) "Programs\DockPolice.lnk"
$shortcutRun  = Join-Path ([Environment]::GetFolderPath("CommonStartup")) "DockPolice.lnk"
$regUninstall = "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\DockPolice"
$certName     = "DockPolice-CodeSign.cer"

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host " Desinstallation de DockPolice" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

$confirm = Read-Host "Confirmer la desinstallation de DockPolice ? (O/N)"
if ($confirm -ne 'O' -and $confirm -ne 'o' -and $confirm -ne 'Y' -and $confirm -ne 'y') {
    Write-Host "Annule." -ForegroundColor Yellow
    exit 0
}

# 1. Arret du processus
Write-Host "==> Arret du processus..." -ForegroundColor Cyan
Get-Process -Name "DockPolice" -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 1

# 2. Retrait du certificat
$certPath = Join-Path $installDir $certName
if (Test-Path $certPath) {
    try {
        $cert = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($certPath)
        $thumbprint = $cert.Thumbprint

        Write-Host "==> Retrait du certificat (empreinte $thumbprint)..." -ForegroundColor Cyan
        Get-ChildItem Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq $thumbprint } | Remove-Item
        Get-ChildItem Cert:\LocalMachine\TrustedPublisher | Where-Object { $_.Thumbprint -eq $thumbprint } | Remove-Item
    } catch {
        Write-Host "    Echec retrait certificat : $_" -ForegroundColor Yellow
    }
}

# 3. Suppression des raccourcis
Write-Host "==> Suppression des raccourcis..." -ForegroundColor Cyan
if (Test-Path $shortcutAll) { Remove-Item $shortcutAll -Force }
if (Test-Path $shortcutRun) { Remove-Item $shortcutRun -Force }

# 4. Suppression entree registre
Write-Host "==> Suppression de l'entree registre..." -ForegroundColor Cyan
if (Test-Path $regUninstall) { Remove-Item $regUninstall -Force -Recurse }

# 5. Suppression du dossier (en dernier, on s'auto-supprime)
Write-Host "==> Suppression du dossier d'installation..." -ForegroundColor Cyan
if (Test-Path $installDir) {
    # On lance un cmd qui attend 2s puis supprime - permet a notre PS de se terminer d'abord
    Start-Process cmd.exe -ArgumentList "/c timeout /t 2 /nobreak > nul && rmdir /s /q `"$installDir`"" -WindowStyle Hidden
}

Write-Host ""
Write-Host "=========================================" -ForegroundColor Green
Write-Host " Desinstallation terminee" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Green
Write-Host ""
Read-Host "Appuie sur Entree pour fermer"
