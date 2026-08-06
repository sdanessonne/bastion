#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère la GPO « Bastion — Verrouillage numérique » : script de DÉMARRAGE (SYSTEM) qui
active le pavé numérique à l'écran de connexion et dans la session de chaque agent.

── POURQUOI UN SCRIPT ET NON UNE STRATÉGIE DE REGISTRE ───────────────────────
L'état du pavé numérique à l'ouverture de session se lit dans
« HKEY_USERS\\.DEFAULT\\Control Panel\\Keyboard\\InitialKeyboardIndicators » —
la ruche du compte SYSTÈME, celle que lit l'écran de connexion.

Or une stratégie de registre (Registry.pol) ne sait écrire que dans HKLM (classe
ORDINATEUR) ou HKCU (classe UTILISATEUR). Ni l'une ni l'autre n'atteint « .DEFAULT ».
Le catalogue de GPO ne peut donc PAS faire ce réglage : il faut du code qui s'exécute
sur le poste sous l'identité SYSTÈME, ce qu'est précisément un script de démarrage.

── LES DEUX PIÈGES ───────────────────────────────────────────────────────────
1. Le DÉMARRAGE RAPIDE de Windows. Il ne redémarre pas vraiment : il restaure l'état
   de la session précédente, pavé numérique compris. La valeur posée dans le registre
   est alors ignorée un démarrage sur deux, ce qui donne exactement l'impression d'un
   réglage « qui ne tient pas ». On le désactive.
2. Les PROFILS DÉJÀ CRÉÉS. Poser la valeur dans « .DEFAULT » ne couvre que l'écran de
   connexion ; chaque agent garde la sienne dans son propre NTUSER.DAT. Au démarrage,
   aucune ruche utilisateur n'est chargée : c'est le seul moment où on peut toutes les
   monter sans risque. On traite aussi le profil MODÈLE, pour les comptes à venir.

Usage : gpo-numlock.py <{GUID}>
"""
import sys, os, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import psfile

SCRIPTS_CSE = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'
SCRIPTS_TOOL = '{40B6664F-4972-11D1-A7CA-0000F87571E3}'

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

def ps1():
    L = [
        "$ErrorActionPreference='SilentlyContinue'",
        "# 2147483650 = 0x80000002 : pave numerique ACTIF (bit 1) + drapeau de forçage",
        "# (bit 31). La valeur « 2 » seule est reprise de l'etat precedent sur Windows 10",
        "# et 11 et ne tient pas ; c'est le drapeau qui rend le reglage effectif.",
        "$VAL='2147483650'",
        "$CLE='Control Panel\\Keyboard'",
        "",
        "# Journal : un script de demarrage n'a personne a qui parler. Sans trace, un",
        "# reglage qui ne prend pas laisse chercher du cote du materiel ou du BIOS.",
        "$jrn=\"$env:windir\\Temp\\bastion-numlock.log\"",
        "function Note($m) { try { Add-Content -Path $jrn -Encoding UTF8 -Value ('{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) } catch { } }",
        "Note '--- demarrage'",
        "",
        "# ── 1. Ecran de connexion ────────────────────────────────────────────────",
        "# « .DEFAULT » est la ruche du compte SYSTEME : c'est elle que lit l'ecran de",
        "# connexion, et elle est toujours chargee.",
        "& reg.exe add \"HKU\\.DEFAULT\\$CLE\" /v InitialKeyboardIndicators /t REG_SZ /d $VAL /f | Out-Null",
        "if ($LASTEXITCODE -eq 0) { Note 'ecran de connexion : pose' } else { Note ('ecran de connexion : ECHEC (code ' + $LASTEXITCODE + ')') }",
        "",
        "# ── 2. Demarrage rapide : RETABLI ────────────────────────────────────────",
        "# Cette strategie a longtemps DESACTIVE le demarrage rapide de Windows, parce",
        "# qu'il restaure l'etat du clavier de la session precedente et fait ignorer le",
        "# reglage ci-dessus un demarrage sur deux.",
        "#",
        "# Le calcul etait mauvais. Desactiver le demarrage rapide impose a CHAQUE poste",
        "# un arret et un demarrage complets, tous les jours — plusieurs dizaines de",
        "# secondes a chaque fois — pour un confort sur l'ecran de connexion. Signale par",
        "# l'exploitant le 2026-08-06 : « la fermeture et le redemarrage sont tres longs ».",
        "#",
        "# On le REMET donc explicitement a 1 au lieu de simplement cesser de l'ecrire :",
        "# les postes deja traites garderaient sinon la valeur 0 pour toujours, et la",
        "# lenteur persisterait sans que rien ne la rattache a sa cause.",
        "$pw='HKLM:\\SYSTEM\\CurrentControlSet\\Control\\Session Manager\\Power'",
        "try {",
        "  $avant = (Get-ItemProperty -Path $pw -Name HiberbootEnabled -ErrorAction SilentlyContinue).HiberbootEnabled",
        "  if ($avant -ne 1) {",
        "    Set-ItemProperty -Path $pw -Name HiberbootEnabled -Value 1 -Type DWord",
        "    Note ('demarrage rapide RETABLI (etait ' + $avant + ', desormais 1) — arrets et demarrages redeviennent rapides')",
        "  } else { Note 'demarrage rapide : deja actif' }",
        "} catch { Note ('demarrage rapide : ECHEC de retablissement — ' + $_.Exception.Message) }",
        "# Consequence assumee : le pave numerique de l'ecran de connexion suit l'etat de",
        "# la session precedente. Il s'applique apres un redemarrage complet, ce que les",
        "# mises a jour de Windows provoquent regulierement.",
        "",
        "# ── 3. Profils ───────────────────────────────────────────────────────────",
        "# Le profil MODELE (nouveaux comptes) puis chaque profil existant. On passe par",
        "# reg.exe et non par le fournisseur de registre de PowerShell : reg.exe rend la",
        "# main et LIBERE la ruche, la ou New-PSDrive laisse un descripteur ouvert qui",
        "# fait echouer le dechargement et laisse le profil verrouille.",
        "$vus = New-Object System.Collections.Generic.HashSet[string]",
        "$ruches = @()",
        "$ruches += (Join-Path $env:SystemDrive 'Users\\Default\\NTUSER.DAT')",
        "foreach ($d in (Get-ChildItem (Join-Path $env:SystemDrive 'Users') -Directory)) {",
        "  $ruches += (Join-Path $d.FullName 'NTUSER.DAT')",
        "}",
        "$ok=0; $ignores=0",
        "foreach ($h in $ruches) {",
        "  if (-not (Test-Path -LiteralPath $h)) { continue }",
        "  # « Default User » est une jonction vers « Default » : sans cette clef de",
        "  # deduplication on monterait deux fois la meme ruche.",
        "  $reel = (Get-Item -LiteralPath $h).FullName",
        "  if (-not $vus.Add($reel.ToLower())) { continue }",
        "  & reg.exe load 'HKU\\BastionNum' \"$reel\" | Out-Null",
        "  if ($LASTEXITCODE -ne 0) { $ignores++; Note (\"ruche occupee, ignoree : $reel\"); continue }",
        "  & reg.exe add \"HKU\\BastionNum\\$CLE\" /v InitialKeyboardIndicators /t REG_SZ /d $VAL /f | Out-Null",
        "  if ($LASTEXITCODE -eq 0) { $ok++ }",
        "  # Le dechargement est INCONDITIONNEL : une ruche laissee montee empeche l'agent",
        "  # d'ouvrir sa session.",
        "  & reg.exe unload 'HKU\\BastionNum' | Out-Null",
        "}",
        "Note (\"profils traites : $ok, ignores : $ignores\")",
    ]
    return "\r\n".join(L) + "\r\n"

def main():
    guid = sys.argv[1]
    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable', file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    startup = os.path.join(sysvol, 'Machine', 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    for d in (os.path.join(sysvol, 'Machine'), os.path.join(sysvol, 'Machine', 'Scripts'), startup):
        if os.path.exists(ref): copy_ntacl(ref, d)

    psfile.ecrire_ps1(os.path.join(startup, 'bastion-numlock.ps1'), ps1())
    psfile.ecrire_cmd(os.path.join(startup, 'bastion-numlock.cmd'),
                      '@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass '
                      '-File "%~dp0bastion-numlock.ps1"\r\n')
    with open(os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini'), 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Startup]\r\n0CmdLine=bastion-numlock.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in ('Startup/bastion-numlock.ps1', 'Startup/bastion-numlock.cmd', 'scripts.ini'):
        p = os.path.join(sysvol, 'Machine', 'Scripts', f)
        os.chmod(p, 0o644)
        if os.path.exists(ref): copy_ntacl(ref, p)

    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)   # incrémente la version ORDINATEUR
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    m = ldb.Message(); m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement('[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL),
                                                       ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d' % newver)

if __name__ == '__main__':
    main()
