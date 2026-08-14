#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Ajoute à la GPO « Bastion — Fond d'écran » un script de DÉMARRAGE qui recopie
l'image du SYSVOL vers le disque du poste.

── POURQUOI UNE COPIE LOCALE ─────────────────────────────────────────────────
La stratégie écrivait dans le registre de l'agent un chemin UNC vers le SYSVOL.
Windows allait donc chercher l'image SUR LE RÉSEAU à chaque ouverture de session.
Quand le partage n'est pas joignable à cet instant précis — réseau pas encore
monté, Wi-Fi lent, passerelle en cours de redémarrage, portable sorti du
commissariat — le bureau reste NOIR, sans le moindre message. L'agent constate
que « le fond d'écran a disparu » et personne ne fait le lien avec le réseau.

C'est aussi ce défaut qui obligeait à déployer « Attendre le réseau à l'ouverture
de session », laquelle ralentit CHAQUE connexion, pour tout le monde.

L'image est donc recopiée une fois, au démarrage, sous « C:\\ProgramData\\Bastion ».
La stratégie pointe ensuite sur ce chemin local : plus aucune dépendance réseau
au moment de l'ouverture de session.

── CE QUI NE DOIT PAS ÉCHOUER EN SILENCE ─────────────────────────────────────
Si la copie rate (disque plein, droits, SYSVOL injoignable au boot), le registre
pointerait sur un fichier ABSENT — soit exactement l'écran noir qu'on cherche à
supprimer, en pire, parce que plus rien ne le rattacherait au réseau. Le script
journalise donc chaque tentative, et CONSERVE la copie précédente tant qu'une
nouvelle n'est pas complète.

Usage : gpo-wallpaper.py <{GUID}> <chemin-image-dans-sysvol>
"""
import sys, os, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import psfile

SCRIPTS_CSE = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'
SCRIPTS_TOOL = '{40B6664F-4972-11D1-A7CA-0000F87571E3}'
LOCAL = 'C:\\ProgramData\\Bastion\\wallpaper.jpg'


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


def ps1(unc):
    L = [
        "$ErrorActionPreference='SilentlyContinue'",
        "$src = '" + unc.replace("'", "") + "'",
        "$dst = '" + LOCAL + "'",
        "$dir = Split-Path $dst -Parent",
        "if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }",
        "$jrn = Join-Path $dir 'wallpaper.log'",
        "function Note($m) { try { Add-Content -Path $jrn -Encoding UTF8 -Value ('{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) } catch { } }",
        "Note '--- demarrage'",
        "",
        "if (-not (Test-Path -LiteralPath $src)) {",
        "  # Dit, jamais avale : au premier demarrage le reseau peut ne pas etre pret.",
        "  # La copie precedente, si elle existe, reste en place et le fond s affiche.",
        "  Note ('source injoignable : ' + $src + ' — copie precedente conservee')",
        "  Note '--- fin'; exit 0",
        "}",
        "",
        "# Ne recopier que si l'image a CHANGE. Une copie de plusieurs mega-octets a",
        "# chaque demarrage, pour un fichier identique, allonge le boot pour rien.",
        "function Empreinte($p) { try { (Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash } catch { '' } }",
        "$hSrc = Empreinte $src",
        "$hDst = if (Test-Path -LiteralPath $dst) { Empreinte $dst } else { '' }",
        "if ($hSrc -ne '' -and $hSrc -eq $hDst) { Note 'image inchangee, rien a faire'; Note '--- fin'; exit 0 }",
        "",
        "# On ecrit d'abord a cote, puis on remplace. Une copie interrompue laisserait",
        "# sinon un fichier tronque a la place du fond d'ecran — donc un ecran noir,",
        "# alors qu'une image valide etait deja la.",
        "$tmp = $dst + '.tmp'",
        "try {",
        "  Copy-Item -LiteralPath $src -Destination $tmp -Force -ErrorAction Stop",
        "  if ((Get-Item $tmp).Length -le 0) { throw 'fichier copie vide' }",
        "  Move-Item -LiteralPath $tmp -Destination $dst -Force -ErrorAction Stop",
        "  # Lecture pour tous : le fond est applique dans la session de l'AGENT, qui",
        "  # n'est pas administrateur. Sans ce droit, il ne verrait rien.",
        "  $acl = Get-Acl $dst",
        "  $r = New-Object System.Security.AccessControl.FileSystemAccessRule(",
        "        'S-1-5-32-545', 'Read', 'Allow')",
        "  $acl.SetAccessRule($r); Set-Acl -Path $dst -AclObject $acl",
        "  Note ('image copiee (' + [math]::Round((Get-Item $dst).Length / 1KB) + ' Ko)')",
        "} catch {",
        "  Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue",
        "  Note ('ECHEC de copie — ' + $_.Exception.Message + ' ; copie precedente conservee')",
        "}",
        "Note '--- fin'",
    ]
    return "\r\n".join(L) + "\r\n"


def main():
    guid, unc = sys.argv[1], sys.argv[2]
    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'],
                           capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable', file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    startup = os.path.join(sysvol, 'Machine', 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    for d in (os.path.join(sysvol, 'Machine'), os.path.join(sysvol, 'Machine', 'Scripts'), startup):
        if os.path.exists(ref): copy_ntacl(ref, d)

    psfile.ecrire_ps1(os.path.join(startup, 'bastion-wallpaper.ps1'), ps1(unc))
    psfile.ecrire_cmd(os.path.join(startup, 'bastion-wallpaper.cmd'),
                      '@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass '
                      '-File "%~dp0bastion-wallpaper.ps1"\r\n')
    with open(os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini'), 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Startup]\r\n0CmdLine=bastion-wallpaper.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in ('Startup/bastion-wallpaper.ps1', 'Startup/bastion-wallpaper.cmd', 'scripts.ini'):
        p = os.path.join(sysvol, 'Machine', 'Scripts', f)
        os.chmod(p, 0o644)
        if os.path.exists(ref): copy_ntacl(ref, p)

    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE,
                       attrs=['versionNumber', 'gPCMachineExtensionNames'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    # Les DEUX moitiés sont incrémentées : la partie ORDINATEUR pour le script qu'on
    # vient d'ajouter, la partie UTILISATEUR pour que le poste relise aussi la
    # stratégie de registre qui porte le nouveau chemin. N'incrémenter qu'une seule
    # donnerait un poste qui copie l'image mais continue de pointer sur l'ancienne
    # adresse — soit exactement l'écran noir qu'on cherche à supprimer.
    newver = ((((cur >> 16) & 0xFFFF) + 1) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))

    # La CSE « Scripts » est AJOUTÉE à celles déjà déclarées, jamais substituée :
    # gpo-apply y a inscrit la CSE « Registre » pour la stratégie de fond d'écran,
    # et l'écraser ferait cesser d'appliquer le réglage lui-même.
    anc = str(res[0]['gPCMachineExtensionNames'][0]) if (res and 'gPCMachineExtensionNames' in res[0]) else ''
    bloc = '[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL)
    if SCRIPTS_CSE not in anc:
        anc = (anc or '') + bloc

    m = ldb.Message(); m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement(anc, ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d' % newver)


if __name__ == '__main__':
    main()
