# =====================================================================
# Signature de DockPolice.exe avec le certificat auto-signe.
# Lance signtool.exe du Windows SDK + horodatage SHA256.
#
# Usage :
#   powershell -ExecutionPolicy Bypass -File .\tools\sign.ps1
#   powershell -ExecutionPolicy Bypass -File .\tools\sign.ps1 -Path "chemin\custom.exe"
# =====================================================================

param(
    [string]$Path = "",
    [string]$PfxPath = ""
)

$ErrorActionPreference = "Stop"

$toolsDir = $PSScriptRoot

if ($PfxPath -eq "") {
    $PfxPath = Join-Path $toolsDir "DockPolice-CodeSign.pfx"
}

if (-not (Test-Path $PfxPath)) {
    Write-Host "[X] Certificat introuvable : $PfxPath" -ForegroundColor Red
    Write-Host "    Lance d'abord : .\tools\new-cert.ps1" -ForegroundColor Yellow
    exit 1
}

# Cibles a signer par defaut
if ($Path -eq "") {
    $candidates = @(
        Join-Path $toolsDir "..\..\dist\DockPolice\DockPolice.exe",
        Join-Path $toolsDir "..\DockLite\bin\Debug\net8.0-windows\DockPolice.exe",
        Join-Path $toolsDir "..\DockLite\bin\Release\net8.0-windows\DockPolice.exe"
    )
    $Path = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $Path) {
        Write-Host "[X] Aucun DockPolice.exe trouve. Lance d'abord publish.ps1 ou dotnet build." -ForegroundColor Red
        exit 1
    }
}

$Path = (Resolve-Path $Path).Path

# Localiser signtool.exe (Windows SDK)
$signtool = Get-ChildItem -Path "C:\Program Files (x86)\Windows Kits\10\bin" -Recurse -Filter "signtool.exe" `
    | Where-Object { $_.FullName -match "x64\\signtool" } `
    | Sort-Object FullName -Descending `
    | Select-Object -First 1

if (-not $signtool) {
    Write-Host "[X] signtool.exe introuvable. Installe le Windows 10/11 SDK." -ForegroundColor Red
    exit 1
}

Write-Host "==> signtool : $($signtool.FullName)" -ForegroundColor Cyan
Write-Host "==> cible    : $Path" -ForegroundColor Cyan

$password = Read-Host -AsSecureString -Prompt "Mot de passe du .pfx"
$bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
$plain = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)

# Serveurs d'horodatage (essayés en cascade). L'horodatage est optionnel
# mais recommande : il fige la date de signature pour qu'elle reste valide
# meme apres expiration du certificat.
$timestampUrls = @(
    "http://timestamp.digicert.com",
    "http://timestamp.sectigo.com",
    "http://time.certum.pl"
)

$signed = $false
foreach ($ts in $timestampUrls) {
    Write-Host "==> Tentative horodatage via $ts..." -ForegroundColor Cyan
    & $signtool.FullName sign `
        /f $PfxPath `
        /p $plain `
        /tr $ts `
        /td sha256 `
        /fd sha256 `
        /d "DockPolice - DIPN Essonne" `
        $Path
    if ($LASTEXITCODE -eq 0) { $signed = $true; break }
}

# Fallback sans horodatage si tous les serveurs echouent (reseau restreint).
if (-not $signed) {
    Write-Host "==> Serveurs d'horodatage inaccessibles, signature sans horodatage..." -ForegroundColor Yellow
    & $signtool.FullName sign `
        /f $PfxPath `
        /p $plain `
        /fd sha256 `
        /d "DockPolice - DIPN Essonne" `
        $Path
}

[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) | Out-Null

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "[OK] Signature OK." -ForegroundColor Green

    # Verification
    Write-Host "==> Verification :" -ForegroundColor Cyan
    & $signtool.FullName verify /pa /v $Path
} else {
    Write-Host "[X] Echec de signature." -ForegroundColor Red
    exit 1
}
