﻿# Bastion - join-domain.ps1
# (c) 2026 Mickael MONESTIER (Mle 110.480). Voir LICENCE.txt.
#
# Jonction du poste au domaine, proposee a la premiere ouverture de session apres
# une installation par le reseau (PXE). Les identifiants sont saisis A L'ECRAN et ne
# sont JAMAIS stockes : ni dans le fichier de reponse, ni sur la passerelle.
#
# Ce script remplace la commande en une ligne qui figurait dans le fichier de reponse
# et qui echouait sans laisser de trace :
#   * « FirstLogonCommands » s'execute SANS ELEVATION pour un compte administrateur
#     ordinaire (filtrage de jeton UAC) -> Add-Computer renvoyait « Acces refuse » ;
#   * le message d'erreur ne pouvait meme pas etre ecrit a la racine de C:\ (droits),
#     donc la fenetre disparaissait sans explication.
# Ici : auto-elevation, attente du controleur de domaine, verification de l'heure,
# journal ecrit dans un dossier accessible, et messages explicites a l'ecran.

$ErrorActionPreference = 'Stop'
$GW     = '192.168.182.1'          # passerelle Bastion (source de temps et serveur web)
$DOMAIN = 'bastion.pn.int'
$LOG    = Join-Path $env:TEMP 'bastion-jonction.log'

function Note($m, $c = 'Gray') { Write-Host $m -ForegroundColor $c
    try { ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m) | Out-File -FilePath $LOG -Append -Encoding utf8 } catch {} }

# -- Elevation : indispensable pour joindre un domaine ------------------------
$moi = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $moi.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Note 'Relance avec les droits administrateur...' 'Yellow'
    try {
        Start-Process powershell.exe -Verb RunAs -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    } catch {
        Write-Host ''
        Write-Host "  Elevation refusee : la jonction au domaine ne peut pas se faire." -ForegroundColor Red
        Write-Host "  Relancez ce script par un clic droit > Executer en tant qu'administrateur." -ForegroundColor Yellow
        Start-Sleep -Seconds 12
    }
    exit
}

Clear-Host
Write-Host ''
Write-Host '  ======================================================' -ForegroundColor Cyan
Write-Host '     Bastion - Rattachement du poste au domaine' -ForegroundColor Cyan
Write-Host '  ======================================================' -ForegroundColor Cyan
Write-Host ''
Note "Poste $env:COMPUTERNAME - domaine vise : $DOMAIN"

# -- Deja joint ? -------------------------------------------------------------
try {
    $cs = Get-CimInstance Win32_ComputerSystem
    if ($cs.PartOfDomain) {
        Note "Ce poste appartient deja au domaine $($cs.Domain). Rien a faire." 'Green'
        Start-Sleep -Seconds 8; exit
    }
} catch {}

# -- Attente du reseau et du controleur de domaine ----------------------------
Write-Host '  Recherche du controleur de domaine...' -ForegroundColor Gray
$ok = $false
for ($i = 1; $i -le 30; $i++) {
    try {
        $r = Resolve-DnsName -Name "_ldap._tcp.dc._msdcs.$DOMAIN" -Type SRV -ErrorAction Stop
        if ($r) { $ok = $true; break }
    } catch { }
    Start-Sleep -Seconds 4
}
if (-not $ok) {
    Note "Controleur de domaine introuvable sur le reseau." 'Red'
    Write-Host ''
    Write-Host "  Verifiez que le poste a bien une adresse IP (cable branche), puis relancez" -ForegroundColor Yellow
    Write-Host "  ce script. Vous pouvez aussi joindre le domaine plus tard :" -ForegroundColor Yellow
    Write-Host "     Parametres > Systeme > Informations > Domaine ou groupe de travail" -ForegroundColor Yellow
    Write-Host ''
    Read-Host '  Appuyez sur Entree pour fermer'
    exit 1
}
Note 'Controleur de domaine trouve.' 'Green'

# -- Heure : au-dela de 5 minutes d'ecart, l'authentification est refusee -----
try {
    $null = & w32tm /config /manualpeerlist:"$GW,0x9" /syncfromflags:manual /update 2>&1
    Start-Service w32time -ErrorAction SilentlyContinue
    $null = & w32tm /resync /rediscover /force 2>&1
    Note 'Heure du poste synchronisee sur la passerelle.'
} catch { Note 'Synchronisation de l heure impossible (on continue).' 'Yellow' }

# -- Identifiants (jamais stockes) + jusqu'a 3 tentatives ---------------------
$joint = $false
for ($essai = 1; $essai -le 3 -and -not $joint; $essai++) {
    Write-Host ''
    Write-Host "  Identifiants d'un compte autorise a joindre des postes au domaine." -ForegroundColor Cyan
    Write-Host "  (Annuler la fenetre = ne pas joindre le poste maintenant)" -ForegroundColor Gray
    $cred = $null
    try { $cred = Get-Credential -Message "Bastion : rattachement au domaine $DOMAIN" } catch {}
    if (-not $cred) {
        Note 'Jonction annulee par l utilisateur.' 'Yellow'
        Write-Host ''
        Write-Host '  Le poste reste hors du domaine. Vous pourrez le rattacher plus tard.' -ForegroundColor Yellow
        Start-Sleep -Seconds 8
        exit 0
    }
    try {
        Write-Host '  Rattachement en cours...' -ForegroundColor Gray
        Add-Computer -DomainName $DOMAIN -Credential $cred -Force -ErrorAction Stop
        $joint = $true
    } catch {
        Note ("Echec (tentative $essai/3) : " + $_.Exception.Message) 'Red'
        if ($essai -lt 3) { Write-Host '  Nouvelle tentative...' -ForegroundColor Yellow }
    }
}
$cred = $null   # on ne conserve rien en memoire

if (-not $joint) {
    Write-Host ''
    Write-Host '  Le rattachement a echoue. Causes les plus frequentes :' -ForegroundColor Red
    Write-Host '   - identifiant ou mot de passe incorrect ;' -ForegroundColor Yellow
    Write-Host '   - compte sans droit de joindre des postes au domaine ;' -ForegroundColor Yellow
    Write-Host '   - horloge du poste trop decalee.' -ForegroundColor Yellow
    Write-Host ''
    Write-Host "  Journal detaille : $LOG" -ForegroundColor Gray
    Read-Host '  Appuyez sur Entree pour fermer'
    exit 1
}

Note "Poste rattache au domaine $DOMAIN." 'Green'
Write-Host ''
Write-Host '  Rattachement reussi.' -ForegroundColor Green
Write-Host '  Le poste va redemarrer pour terminer la configuration.' -ForegroundColor Cyan
Write-Host ''
for ($s = 15; $s -ge 1; $s--) { Write-Host "`r  Redemarrage dans $s secondes... (Ctrl+C pour annuler)   " -NoNewline -ForegroundColor Gray; Start-Sleep -Seconds 1 }
Restart-Computer -Force
