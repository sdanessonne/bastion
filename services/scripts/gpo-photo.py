#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère/actualise la GPO « Bastion — Photo de l'agent » : la photo de chaque fonctionnaire
devient son IMAGE DE COMPTE Windows (écran de connexion, menu Démarrer, Paramètres > Comptes),
sur tout le parc, sans passer poste par poste.

── POURQUOI UN SCRIPT DE DÉMARRAGE ET NON D'OUVERTURE DE SESSION ────────────────
Deux contraintes se combinent, et une seule des deux est évidente.

1. DROITS. Poser l'image de compte exige d'écrire dans C:\\Users\\Public\\AccountPictures\\<SID>\\
   ET dans HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\AccountPicture\\Users\\<SID>.
   Ces deux opérations sont réservées aux ADMINISTRATEURS. Or un script d'ouverture de session
   de GPO s'exécute avec les droits de l'agent : il en est tout simplement incapable.

2. MOMENT — c'est le point que l'on oublie. L'écran de connexion affiche la vignette AVANT
   que l'agent ne se connecte. Une image posée PENDANT la session n'apparaît donc qu'à la
   session SUIVANTE. C'est exactement la limite de l'outil manuel Install-BastionPhoto.cmd,
   qui n'installe qu'une tâche « à l'ouverture de session ».

Le script de DÉMARRAGE règle les deux d'un coup : il tourne en SYSTEM (donc il a les droits)
et AVANT toute session (donc l'écran de connexion est déjà correct). Il pose la photo de TOUS
les profils déjà présents sur le poste, énumérés dans la base de registre.

La tâche « à l'ouverture de session » reste installée en complément, pour deux cas que le
démarrage ne couvre pas : l'agent qui ouvre sa TOUTE PREMIÈRE session sur ce poste (son profil
n'existait pas encore au boot), et le changement de photo dans la console, qui se propage
ainsi sans attendre le prochain redémarrage.

Usage : gpo-photo.py <{GUID}> <IP de la passerelle> <jeton>
"""
import sys, os, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import psfile

SCRIPTS_CSE  = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'
SCRIPTS_TOOL = '{40B6664F-4972-11D1-A7CA-0000F87571E3}'

# Le gabarit utilise __GW__ / __TOKEN__ et un remplacement par chaîne : surtout PAS de
# formatage « % », car « % » est l'alias de ForEach-Object en PowerShell et le script en
# est truffé — le moindre oubli d'échappement casserait la génération.
PS1 = r"""# Bastion - photo de l'agent (image de compte Windows).
# Genere par gpo-photo.py ; toute modification locale sera ecrasee au prochain demarrage.
#
# Execute en SYSTEM par la GPO, de deux facons :
#   - au DEMARRAGE du poste : pose la photo de tous les profils deja presents, pour que
#     l'ECRAN DE CONNEXION soit correct avant meme qu'un agent ne se connecte ;
#   - a l'OUVERTURE DE SESSION (tache planifiee) : rattrape un profil nouveau et propage
#     un changement de photo sans attendre le prochain redemarrage.
$ErrorActionPreference = 'SilentlyContinue'
$GW    = '__GW__'
$TOKEN = '__TOKEN__'
$dir   = 'C:\ProgramData\Bastion'
$log   = Join-Path $dir 'photo.log'
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
function Note($m) { ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) |
    Out-File -FilePath $log -Append -Encoding utf8 }

Note '--- Images de compte (SYSTEM) ---'

# UNE SEULE sonde reseau, courte, AVANT toute autre chose. Au demarrage la carte reseau
# n'est pas forcement prete : sans cette sonde, CHAQUE profil paierait un delai d'attente
# complet et retarderait d'autant l'affichage de l'ecran de connexion.
$vivant = $false
for ($i = 0; $i -lt 10; $i++) {
    try {
        Invoke-WebRequest -Uri "http://${GW}:2080/" -Method Head -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop | Out-Null
        $vivant = $true; break
    } catch { Start-Sleep -Seconds 3 }
}
if (-not $vivant) { Note 'Passerelle injoignable : rien n a ete pose ni retire.'; exit }

# TLS 1.2 au minimum : la console refuse TLS 1.0 et 1.1. Selon l'etat de .NET Framework,
# PowerShell 5.1 peut encore proposer TLS 1.0 et l'appel echouerait sur un « canal SSL/TLS
# impossible a creer » qui ne dit rien de sa cause. 3072 = Tls12, 12288 = Tls13 ; try
# separes car une version de .NET qui ignore Tls13 leve a l'affectation.
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 3072 } catch { }
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor 12288 } catch { }

$TAILLES = 32, 40, 48, 96, 192, 208, 240, 424, 448, 1080

function Poser-Photo($login, $sid) {
    # Rend $true si une photo a ete posee, $false sinon. N'ecrit rien si la photo en place
    # est deja la bonne : au demarrage comme a chaque ouverture de session, ce script
    # repasse sur les memes comptes et il ne faut pas reecrire dix fichiers a chaque fois.
    $tmp = Join-Path $env:TEMP ("bastion-photo-$login.png")
    $cb = [Net.ServicePointManager]::ServerCertificateValidationCallback
    try {
        # Le certificat de la console est celui de l'autorite interne de Bastion. Tant que la
        # strategie « Certificat racine » n'est pas appliquee, le poste ne le reconnait pas.
        # On accepte donc le certificat LE TEMPS DE CET APPEL, puis on retablit le controle.
        # Compromis borne : la connexion ne quitte pas le reseau local et vise la passerelle.
        [Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
        # « ${GW} » et non « $GW » : suivi de « : », PowerShell lirait le nom comme un
        # qualificateur de portee et la variable vaudrait vide.
        # Delai court : ce script s'execute AVANT l'ecran de connexion. Mieux vaut renoncer
        # pour un agent que faire attendre tout le poste ; la tache d'ouverture de session
        # rattrapera. La passerelle a deja repondu a la sonde, 8 s sont largement suffisantes.
        Invoke-WebRequest -Uri "https://${GW}:8443/api.php?action=poste.photo&user=$login" `
            -Headers @{ Authorization = "Bearer $TOKEN" } -OutFile $tmp -UseBasicParsing `
            -TimeoutSec 8 -ErrorAction Stop
    } catch {
        # 404 = cet agent n'a pas de photo dans la console. Ce n'est pas une panne : on le
        # note en clair pour ne pas le confondre avec une vraie erreur reseau.
        $code = 0
        if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
        if ($code -eq 404) { Note "$login : aucune photo dans la console" }
        else { Note ("$login : echec de recuperation - " + $_.Exception.Message) }
        return $false
    } finally { [Net.ServicePointManager]::ServerCertificateValidationCallback = $cb }

    if (-not (Test-Path $tmp) -or (Get-Item $tmp).Length -lt 100) { return $false }
    $sig  = (Get-FileHash $tmp -Algorithm SHA256).Hash
    $marq = Join-Path $dir ("photo-$login.tag")
    $dest = "C:\Users\Public\AccountPictures\$sid"
    # Le temoin ne vaut que si les fichiers sont TOUJOURS la : un profil supprime puis
    # recree, ou un nettoyage de disque, laisserait sinon le poste sans image alors que
    # le temoin pretendrait le contraire.
    if ((Test-Path $marq) -and (Test-Path (Join-Path $dest 'Image448.jpg')) -and
        (Get-Content $marq -Raw).Trim() -eq $sig) { Remove-Item $tmp -Force; return $false }

    if (-not (Test-Path $dest)) { New-Item -ItemType Directory -Path $dest -Force | Out-Null }
    Add-Type -AssemblyName System.Drawing
    $src = [Drawing.Image]::FromFile($tmp)
    try {
        # Windows attend une image par taille et choisit la plus proche selon l'ecran.
        foreach ($px in $TAILLES) {
            $bmp = New-Object Drawing.Bitmap $px, $px
            $g = [Drawing.Graphics]::FromImage($bmp)
            $g.InterpolationMode = 'HighQualityBicubic'
            $g.DrawImage($src, 0, 0, $px, $px)
            $bmp.Save((Join-Path $dest "Image$px.jpg"), [Drawing.Imaging.ImageFormat]::Jpeg)
            $g.Dispose(); $bmp.Dispose()
        }
    } finally { $src.Dispose(); Remove-Item $tmp -Force }

    $rk = "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AccountPicture\Users\$sid"
    if (-not (Test-Path $rk)) { New-Item -Path $rk -Force | Out-Null }
    foreach ($px in $TAILLES) {
        Set-ItemProperty -Path $rk -Name "Image$px" -Value (Join-Path $dest "Image$px.jpg")
    }
    Set-Content -Path $marq -Value $sig
    Note "$login : photo posee ($sid)"
    return $true
}

# ---- Comptes a traiter : les profils presents sur le poste ------------------------------
# On lit la liste des profils dans la base de registre plutot que de se limiter a la session
# en cours : au demarrage, justement, il n'y a AUCUNE session en cours.

# Comptes LOCAUX du poste, a ecarter : ils n'ont pas de fiche d'agent dans la console.
# Attention, le prefixe « S-1-5-21- » NE SUFFIT PAS a distinguer domaine et local : les
# comptes locaux le portent aussi. Sans ce filtre, le compte d'administration local du
# poste declencherait un appel reseau inutile a chaque demarrage.
# Le filtre « LocalAccount=True » est indispensable pour une autre raison encore : sans lui,
# Win32_UserAccount enumere TOUT l'annuaire et le demarrage s'eternise.
$locaux = @{}
foreach ($u in (Get-CimInstance Win32_UserAccount -Filter 'LocalAccount=True' -ErrorAction SilentlyContinue)) {
    $locaux[$u.SID] = $true
}

$comptes = @{}
$pl = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList'
foreach ($k in (Get-ChildItem $pl -ErrorAction SilentlyContinue)) {
    $sid = $k.PSChildName
    # On ecarte les comptes de service SYSTEM (S-1-5-18), LOCAL SERVICE (19) et
    # NETWORK SERVICE (20), qui ne correspondent a aucun agent.
    if ($sid -notmatch '^S-1-5-21-\d') { continue }
    if ($locaux.ContainsKey($sid)) { continue }
    $ipath = (Get-ItemProperty $k.PSPath -Name ProfileImagePath -ErrorAction SilentlyContinue).ProfileImagePath
    if (-not $ipath) { continue }
    # Profil dormant : un agent qui n'est pas revenu depuis 4 mois n'a pas a laisser sa
    # photo sur ce poste. C'est aussi ce qui evite qu'un poste partage accumule les
    # visages de tout un commissariat.
    $ntu = Join-Path $ipath 'NTUSER.DAT'
    if ((Test-Path $ntu) -and (Get-Item $ntu).LastWriteTime -lt (Get-Date).AddDays(-120)) {
        Note ("$sid : profil dormant, ignore"); continue
    }
    # Nom de compte par l'annuaire, avec REPLI OBLIGATOIRE sur le nom du dossier de profil :
    # au demarrage, une horloge decalee fait echouer Kerberos donc la resolution du SID -- et
    # ce sont precisement les postes qui nous occupent. Sans ce repli, le mecanisme serait
    # mort exactement la ou on en a besoin.
    $login = ''
    try {
        $login = (New-Object Security.Principal.SecurityIdentifier($sid)).Translate([Security.Principal.NTAccount]).Value.Split('\')[-1]
    } catch { }
    if (-not $login) { $login = (Split-Path $ipath -Leaf).Split('.')[0] }
    if (-not $login) { continue }
    $comptes[$sid] = $login
}

# Session en cours, s'il y en a une (cas de la tache a l'ouverture de session). Son profil
# peut ne pas encore figurer dans ProfileList a la toute premiere connexion : on l'ajoute.
$who = (Get-CimInstance Win32_ComputerSystem).UserName
if ($who) {
    try {
        $s = (New-Object Security.Principal.NTAccount($who)).Translate([Security.Principal.SecurityIdentifier]).Value
        if (-not $locaux.ContainsKey($s)) { $comptes[$s] = $who.Split('\')[-1] }
    } catch { }
}

$n = 0
foreach ($sid in $comptes.Keys) { if (Poser-Photo $comptes[$sid] $sid) { $n++ } }

# ---- PURGE : une photo ne doit pas survivre au profil qu'elle represente ----------------
# Ce sont des visages de fonctionnaires de police : les laisser sur un poste apres le
# depart de l'agent n'a aucune justification. La tache d'origine n'a jamais rien retire.
# On ne purge QUE si la passerelle a repondu (sortie plus haut sinon) : sur une panne
# reseau, $comptes serait vide et on effacerait tout le parc.
$racine = 'C:\Users\Public\AccountPictures'
$purges = 0
if (Test-Path $racine) {
    foreach ($d in (Get-ChildItem $racine -Directory -ErrorAction SilentlyContinue)) {
        if ($comptes.ContainsKey($d.Name)) { continue }
        if ($d.Name -notmatch '^S-1-5-21-\d') { continue }
        Remove-Item $d.FullName -Recurse -Force
        Remove-Item ("HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\AccountPicture\Users\" + $d.Name) -Recurse -Force
        $purges++
    }
}
Note ("Fin : {0} profil(s) examine(s), {1} photo(s) mise(s) a jour, {2} purgee(s)." -f $comptes.Count, $n, $purges)
"""

# Script de démarrage : installe la copie locale + la tâche, puis fait le travail tout de suite.
CMD_STARTUP = r"""@echo off
REM Bastion - deploiement de la photo de l'agent (execute en SYSTEM au demarrage).
REM
REM On COPIE le script en local avant de l'executer : la tache planifiee, elle, tournera a
REM l'ouverture de session, a un moment ou le SYSVOL n'est pas toujours joignable. Un chemin
REM local ne depend ni du reseau ni de Kerberos.
if not exist "C:\ProgramData\Bastion" mkdir "C:\ProgramData\Bastion"
copy /Y "%~dp0bastion-photo.ps1" "C:\ProgramData\Bastion\bastion-photo.ps1" >nul 2>&1

REM Tache a l'ouverture de session : rattrape un profil nouveau et propage un changement de
REM photo. /f la recree a chaque demarrage, ce qui la remet d'aplomb si elle a ete alteree.
schtasks /create /tn "Bastion - Photo de l'agent" /ru SYSTEM /rl HIGHEST /sc ONLOGON ^
  /delay 0000:20 /tr "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"C:\ProgramData\Bastion\bastion-photo.ps1\"" /f >nul 2>&1

REM Et on pose les photos MAINTENANT, avant toute session : c'est ce qui rend l'ecran de
REM connexion correct des ce demarrage, au lieu d'attendre la session suivante.
powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\ProgramData\Bastion\bastion-photo.ps1"
"""


def copy_ntacl(src, dst):
    try:
        r = subprocess.run(['getfattr', '--absolute-names', '-n', 'security.NTACL', '-e', 'hex', src],
                           capture_output=True, text=True)
        val = ''
        for line in r.stdout.splitlines():
            if line.startswith('security.NTACL='):
                val = line.split('=', 1)[1].strip()
        if val:
            subprocess.run(['setfattr', '-n', 'security.NTACL', '-v', val, dst], capture_output=True)
    except Exception:
        pass


def main():
    if len(sys.argv) < 4:
        print('usage: gpo-photo.py <{GUID}> <GW_IP> <jeton>', file=sys.stderr); sys.exit(2)
    guid, gw, token = sys.argv[1], sys.argv[2], sys.argv[3]

    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'],
                           capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable (%s)' % sysvol, file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    startup = os.path.join(sysvol, 'Machine', 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    for d in (os.path.join(sysvol, 'Machine'), os.path.join(sysvol, 'Machine', 'Scripts'), startup):
        if os.path.exists(ref):
            copy_ntacl(ref, d)

    corps = PS1.replace('__GW__', gw).replace('__TOKEN__', token)
    p_ps1 = psfile.ecrire_ps1(os.path.join(startup, 'bastion-photo.ps1'), corps)
    p_cmd = psfile.ecrire_cmd(os.path.join(startup, 'bastion-photo.cmd'), CMD_STARTUP)
    p_ini = os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini')
    with open(p_ini, 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Startup]\r\n0CmdLine=bastion-photo.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in (p_ps1, p_cmd, p_ini):
        os.chmod(f, 0o644)
        if os.path.exists(ref):
            copy_ntacl(ref, f)

    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    # versionNumber : mot BAS = version ORDINATEUR (celle qu'on incrémente ici), mot HAUT =
    # version utilisateur. Sans incrément, le poste considère la stratégie inchangée.
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))

    m = ldb.Message()
    m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement('[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL),
                                                       ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d' % newver)


if __name__ == '__main__':
    main()
