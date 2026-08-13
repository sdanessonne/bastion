# =====================================================================
# DockPolice - Installeur
# =====================================================================
# A executer depuis le dossier de distribution (qui contient
# DockPolice.exe, apps.json et DockPolice-CodeSign.cer).
#
# Auto-elevation en admin si necessaire.
#
#   1. Importe le certificat dans Trusted Root + Trusted Publishers
#   2. Copie les fichiers vers C:\Program Files\DockPolice
#   3. Cree un raccourci dans le Menu Demarrer
#   4. Cree un raccourci dans le demarrage automatique
#   5. Enregistre l'application dans le registre (pour desinstallation)
#
# Usage : clic droit sur le script -> Executer avec PowerShell
#   ou : powershell -ExecutionPolicy Bypass -File .\install-dockpolice.ps1
# =====================================================================

$ErrorActionPreference = "Stop"

# --- Auto-elevation en admin ---
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "Privileges administrateur requis. Relancement en mode eleve..." -ForegroundColor Yellow
    $args = "-NoProfile -ExecutionPolicy Bypass -File `"$PSCommandPath`""
    Start-Process powershell.exe -ArgumentList $args -Verb RunAs
    exit
}

$sourceDir   = $PSScriptRoot
$installDir  = "C:\Program Files\DockPolice"
$exeName     = "DockPolice.exe"
$certName    = "DockPolice-CodeSign.cer"
$shortcutAll = Join-Path ([Environment]::GetFolderPath("CommonStartMenu")) "Programs\DockPolice.lnk"
$shortcutRun = Join-Path ([Environment]::GetFolderPath("CommonStartup")) "DockPolice.lnk"
$regUninstall = "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\DockPolice"

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host " Installation de DockPolice" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# --- Verifications ---
$exePath  = Join-Path $sourceDir $exeName
$certPath = Join-Path $sourceDir $certName

if (-not (Test-Path $exePath)) {
    Write-Host "[X] Introuvable : $exePath" -ForegroundColor Red
    Write-Host "    Lance ce script depuis le dossier qui contient DockPolice.exe" -ForegroundColor Yellow
    Read-Host "Appuie sur Entree pour fermer"
    exit 1
}

# --- 1. Import du certificat ---
if (Test-Path $certPath) {
    Write-Host "==> Import du certificat dans 'Autorites racines de confiance'..." -ForegroundColor Cyan
    Import-Certificate -FilePath $certPath -CertStoreLocation Cert:\LocalMachine\Root | Out-Null

    Write-Host "==> Import du certificat dans 'Editeurs approuves'..." -ForegroundColor Cyan
    Import-Certificate -FilePath $certPath -CertStoreLocation Cert:\LocalMachine\TrustedPublisher | Out-Null

    Write-Host "    Certificat installe." -ForegroundColor Green
} else {
    Write-Host "(Pas de certificat .cer fourni - signature non installee)" -ForegroundColor Yellow
}

# --- 2. Copie des fichiers ---
Write-Host "==> Copie des fichiers vers $installDir..." -ForegroundColor Cyan

# Stop si l'app tourne deja
$running = Get-Process -Name "DockPolice" -ErrorAction SilentlyContinue
if ($running) {
    Write-Host "    DockPolice est en cours d'execution. Arret..." -ForegroundColor Yellow
    Stop-Process -Name "DockPolice" -Force
    Start-Sleep -Seconds 1
}

if (-not (Test-Path $installDir)) {
    New-Item -ItemType Directory -Path $installDir -Force | Out-Null
}

# Copie tout sauf les scripts d'install
Get-ChildItem -Path $sourceDir -File | Where-Object {
    $_.Name -notmatch '\.ps1$' -and $_.Name -notmatch '\.cer$'
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $installDir -Force
}

# Copie le .cer pour reference (utile pour la desinstallation)
if (Test-Path $certPath) {
    Copy-Item -Path $certPath -Destination $installDir -Force
}

Write-Host "    Fichiers copies." -ForegroundColor Green

# --- 3. Raccourcis ---
Write-Host "==> Creation des raccourcis..." -ForegroundColor Cyan

$wsh = New-Object -ComObject WScript.Shell

$lnkAll = $wsh.CreateShortcut($shortcutAll)
$lnkAll.TargetPath = Join-Path $installDir $exeName
$lnkAll.WorkingDirectory = $installDir
$lnkAll.IconLocation = Join-Path $installDir $exeName
$lnkAll.Description = "DockPolice - Lanceur d'applications et SAV"
$lnkAll.Save()

# Le raccourci de demarrage automatique n'est cree QUE si le service Agent
# n'est pas installe. Sinon le service se charge de lancer le dock dans
# chaque session utilisateur via CreateProcessAsUser.
$agentService = Get-Service -Name "DockPoliceAgent" -ErrorAction SilentlyContinue
if ($agentService) {
    Write-Host "    Service DockPoliceAgent detecte : pas de raccourci Startup (le service lance le dock)." -ForegroundColor Yellow
    if (Test-Path $shortcutRun) {
        Remove-Item $shortcutRun -Force -ErrorAction SilentlyContinue
    }
    return
}

$lnkRun = $wsh.CreateShortcut($shortcutRun)
$lnkRun.TargetPath = Join-Path $installDir $exeName
$lnkRun.WorkingDirectory = $installDir
$lnkRun.IconLocation = Join-Path $installDir $exeName
$lnkRun.Description = "DockPolice (demarrage automatique)"
$lnkRun.Save()

Write-Host "    Raccourci Menu Demarrer  : $shortcutAll" -ForegroundColor Green
Write-Host "    Raccourci Demarrage auto : $shortcutRun" -ForegroundColor Green

# --- 4. Enregistrement registre (pour desinstallation Panneau de config) ---
Write-Host "==> Enregistrement dans le registre..." -ForegroundColor Cyan

$exeFullPath = Join-Path $installDir $exeName
$version = "1.0.0"
try {
    $verInfo = (Get-Item $exeFullPath).VersionInfo
    if ($verInfo.ProductVersion) { $version = $verInfo.ProductVersion }
} catch {}

if (-not (Test-Path $regUninstall)) {
    New-Item -Path $regUninstall -Force | Out-Null
}
New-ItemProperty -Path $regUninstall -Name "DisplayName"     -Value "DockPolice" -PropertyType String -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "DisplayVersion"  -Value $version -PropertyType String -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "Publisher"       -Value "DIPN Essonne - Service informatique" -PropertyType String -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "InstallLocation" -Value $installDir -PropertyType String -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "DisplayIcon"     -Value $exeFullPath -PropertyType String -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "UninstallString" -Value "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$installDir\uninstall-dockpolice.ps1`"" -PropertyType String -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "NoModify"        -Value 1 -PropertyType DWord -Force | Out-Null
New-ItemProperty -Path $regUninstall -Name "NoRepair"        -Value 1 -PropertyType DWord -Force | Out-Null

# Copie le script de desinstallation a cote
$uninstallSource = Join-Path $sourceDir "uninstall-dockpolice.ps1"
if (Test-Path $uninstallSource) {
    Copy-Item -Path $uninstallSource -Destination $installDir -Force
}

Write-Host "    Visible dans 'Applications & fonctionnalites' Windows." -ForegroundColor Green

# --- 5. Verification finale ---
Write-Host ""
Write-Host "==> Verification de la signature..." -ForegroundColor Cyan
$sig = Get-AuthenticodeSignature $exeFullPath
Write-Host "    Statut : $($sig.Status)" -ForegroundColor $(if ($sig.Status -eq 'Valid') { 'Green' } else { 'Yellow' })
Write-Host "    Signataire : $($sig.SignerCertificate.Subject)" -ForegroundColor Green

# --- 6. Lancement optionnel ---
Write-Host ""
Write-Host "=========================================" -ForegroundColor Green
Write-Host " Installation terminee" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Green
Write-Host ""
Write-Host "DockPolice est installe dans : $installDir" -ForegroundColor White
Write-Host "Demarrera automatiquement au prochain login." -ForegroundColor White
Write-Host ""

$launch = Read-Host "Lancer DockPolice maintenant ? (O/N)"
if ($launch -eq 'O' -or $launch -eq 'o' -or $launch -eq 'Y' -or $launch -eq 'y') {
    Start-Process -FilePath $exeFullPath -WorkingDirectory $installDir
}
