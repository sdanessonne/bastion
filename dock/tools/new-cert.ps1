# =====================================================================
# Generation d'un certificat auto-signe de signature de code
# pour DockPolice. A executer UNE SEULE FOIS.
#
# Produit deux fichiers dans tools\ :
#   - DockPolice-CodeSign.pfx   (cle privee + certificat - a proteger !)
#   - DockPolice-CodeSign.cer   (certificat public - a deployer sur les postes)
#
# Usage :
#   powershell -ExecutionPolicy Bypass -File .\tools\new-cert.ps1
# =====================================================================

$ErrorActionPreference = "Stop"

$toolsDir = $PSScriptRoot
$pfxPath  = Join-Path $toolsDir "DockPolice-CodeSign.pfx"
$cerPath  = Join-Path $toolsDir "DockPolice-CodeSign.cer"

if (Test-Path $pfxPath) {
    Write-Host "Le certificat existe deja : $pfxPath" -ForegroundColor Yellow
    Write-Host "Supprime-le d'abord si tu veux en generer un nouveau." -ForegroundColor Yellow
    exit 0
}

Write-Host "==> Generation d'un certificat auto-signe pour DockPolice..." -ForegroundColor Cyan

# Demande du mot de passe pour proteger la cle privee
$password = Read-Host -AsSecureString -Prompt "Mot de passe pour proteger le .pfx (sera demande a chaque signature)"

# Creation du certificat dans le magasin utilisateur
$cert = New-SelfSignedCertificate `
    -Type CodeSigningCert `
    -Subject "CN=DockPolice, O=DIPN Essonne, C=FR" `
    -KeyAlgorithm RSA `
    -KeyLength 2048 `
    -KeyUsage DigitalSignature `
    -CertStoreLocation "Cert:\CurrentUser\My" `
    -NotAfter (Get-Date).AddYears(5) `
    -FriendlyName "DockPolice Code Signing"

Write-Host "  Empreinte : $($cert.Thumbprint)" -ForegroundColor Green

# Export PFX (avec cle privee)
Export-PfxCertificate `
    -Cert $cert `
    -FilePath $pfxPath `
    -Password $password | Out-Null

# Export CER (cle publique uniquement, pour deploiement)
Export-Certificate `
    -Cert $cert `
    -FilePath $cerPath | Out-Null

Write-Host ""
Write-Host "[OK] Certificat genere." -ForegroundColor Green
Write-Host "  PFX (prive)   : $pfxPath" -ForegroundColor Green
Write-Host "  CER (public)  : $cerPath" -ForegroundColor Green
Write-Host ""
Write-Host "Garde le .pfx en lieu sur (et son mot de passe)." -ForegroundColor Yellow
Write-Host "Le .cer doit etre installe dans 'Autorites de certification racine de confiance'" -ForegroundColor Yellow
Write-Host "sur chaque poste cible (manuellement ou via GPO)." -ForegroundColor Yellow
