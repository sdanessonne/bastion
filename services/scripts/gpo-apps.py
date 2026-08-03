#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère/actualise la GPO « Bastion — Applications » : un script de DÉMARRAGE
(exécuté en tant que SYSTEM au boot du poste) télécharge depuis la passerelle et
installe en silence les applications sélectionnées dans le « store ». Un marqueur
registre évite la réinstallation à chaque démarrage.

Usage : gpo-apps.py <{GUID}> <apps.json>
apps.json : {"gw":"192.168.182.1","apps":[
  {"marker":"7zip","url":"http://.../apps/7zip.msi","args":"/qn","msi":true}, ...]}
"""
import sys, os, json, subprocess
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import psfile

# CSE « Scripts » (démarrage/arrêt) + outil administratif standard.
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

def ps1(apps, retraits=()):
    """Construit le script PowerShell d'installation."""
    lines = [
        "$ErrorActionPreference = 'SilentlyContinue'",
        "$base = 'HKLM:\\Software\\Bastion\\Apps'",
        "New-Item -Path $base -Force | Out-Null",
        "$apps = @(",
    ]
    # La VIRGULE ne suit JAMAIS le dernier élément : « @( …, ) » est une erreur de syntaxe
    # PowerShell (« Expression manquante après , »), et le script échouait alors à l'ANALYSE —
    # donc avant la moindre ligne de code, sans installer quoi que ce soit ni écrire de journal.
    # C'est la raison pour laquelle aucune application ne s'installait.
    for i, a in enumerate(apps):
        marker = a['marker'].replace("'", "")
        url = a['url'].replace("'", "")
        args = str(a.get('args', '')).replace("'", "''")
        msi = 'true' if a.get('msi') else 'false'
        virgule = ',' if i < len(apps) - 1 else ''
        lines.append("  @{ marker='%s'; url='%s'; args='%s'; msi=$%s }%s"
                     % (marker, url, args, msi, virgule))
    lines += [
        ")",
        "# JOURNAL : sans lui, un echec restait totalement invisible (le script avale ses erreurs",
        "# pour ne pas deranger l'agent). On trace donc chaque etape dans un fichier lisible, que",
        "# l'outil « Install-BastionApps.cmd » affiche ensuite a l'ecran.",
        "$dir='C:\\ProgramData\\Bastion'",
        "if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }",
        "$log=Join-Path $dir 'apps.log'",
        "function Note($m) { ('[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) |",
        "    Out-File -FilePath $log -Append -Encoding utf8 }",
        "Note ('--- Installation des applications (utilisateur ' + $env:USERNAME + ') ---')",
        "foreach ($a in $apps) {",
        "  if (Get-ItemProperty -Path $base -Name $a.marker -ErrorAction SilentlyContinue) {",
        "    Note ($a.marker + ' : deja installe, ignore'); continue }",
        "  $ext = if ($a.msi) { 'msi' } else { 'exe' }",
        "  $tmp = Join-Path $env:TEMP ('bastion_' + $a.marker + '.' + $ext)",
        "  try {",
        "    Note ($a.marker + ' : telechargement ' + $a.url)",
        "    Invoke-WebRequest -Uri $a.url -OutFile $tmp -UseBasicParsing -TimeoutSec 600 -ErrorAction Stop",
        "    $mo = [int]((Get-Item $tmp).Length/1MB)",
        "    Note ($a.marker + ' : recu ' + $mo + ' Mo, installation en cours')",
        "    if ($a.msi) { $p = Start-Process msiexec.exe -ArgumentList ('/i \"' + $tmp + '\" ' + $a.args) -Wait -PassThru }",
        "    else        { $p = Start-Process $tmp -ArgumentList $a.args -Wait -PassThru }",
        "    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010) {",
        "      Set-ItemProperty -Path $base -Name $a.marker -Value 1",
        "      Note ($a.marker + ' : INSTALLE (code ' + $p.ExitCode + ')')",
        "    } else {",
        "      Note ($a.marker + ' : ECHEC installation, code ' + $p.ExitCode)",
        "    }",
        "    Remove-Item $tmp -Force -ErrorAction SilentlyContinue",
        "  } catch {",
        "    Note ($a.marker + ' : ECHEC — ' + $_.Exception.Message)",
        "  }",
        "}",
    ]

    # ── RETRAITS ─────────────────────────────────────────────────────────────
    # Une application décochée dans la console doit DISPARAÎTRE des postes, sinon
    # « désactivé » ne veut rien dire pour le parc : le script cessait simplement de
    # l'installer et elle restait en place partout où elle l'était déjà.
    #
    # Règle absolue : on ne désinstalle QUE si le marqueur posé par Bastion est présent.
    # Il prouve que c'est NOUS qui avons installé ce logiciel. Sans cette condition, une
    # application homonyme installée par le service informatique ou livrée avec l'image
    # du poste serait retirée sans que personne ne l'ait demandé.
    lines += [
        "",
        "$retraits = @(",
    ]
    for i, r in enumerate(retraits):
        marker = str(r.get('marker', '')).replace("'", "")
        nom = str(r.get('nom', '')).replace("'", "''")
        virgule = ',' if i < len(retraits) - 1 else ''
        lines.append("  @{ marker='%s'; nom='%s' }%s" % (marker, nom, virgule))
    lines += [
        ")",
        "foreach ($d in $retraits) {",
        "  if (-not (Get-ItemProperty -Path $base -Name $d.marker -ErrorAction SilentlyContinue)) { continue }",
        "  # Le marqueur existe : Bastion a installe ce logiciel, il peut le retirer.",
        "  $u = $null",
        "  foreach ($rc in @('HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*',",
        "                    'HKLM:\\Software\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*')) {",
        "    $u = Get-ItemProperty $rc -ErrorAction SilentlyContinue |",
        "         Where-Object { $_.DisplayName -and $_.DisplayName -like ('*' + $d.nom + '*') } |",
        "         Select-Object -First 1",
        "    if ($u) { break }",
        "  }",
        "  if (-not $u) {",
        "    # Absent des programmes installes : deja retire a la main. On efface le marqueur,",
        "    # sinon le poste rechercherait ce logiciel a chaque demarrage, indefiniment.",
        "    Remove-ItemProperty -Path $base -Name $d.marker -ErrorAction SilentlyContinue",
        "    Note ($d.marker + ' (' + $d.nom + ') : deja absent, marqueur efface'); continue",
        "  }",
        "  # « QuietUninstallString » est la commande SILENCIEUSE fournie par l'editeur. A",
        "  # defaut, seul MSI garantit un retrait sans interface : « /I{GUID} » devient",
        "  # « /X{GUID} », plus « /qn /norestart ». Pour un .exe sans commande silencieuse,",
        "  # on NE lance rien : une fenetre d'assistant surgissant au demarrage d'un poste",
        "  # bloquerait la session sans que personne ne comprenne pourquoi.",
        "  $cmd = $u.QuietUninstallString",
        "  if (-not $cmd -and $u.UninstallString -match 'msiexec') {",
        "    $cmd = ($u.UninstallString -replace '/I\\{', '/X{') + ' /qn /norestart'",
        "  }",
        "  if (-not $cmd) {",
        "    Note ($d.marker + ' (' + $d.nom + ') : AUCUNE desinstallation silencieuse fournie par l editeur — a retirer a la main')",
        "    continue",
        "  }",
        "  try {",
        "    Note ($d.marker + ' (' + $d.nom + ') : desinstallation en cours')",
        "    $p = Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', $cmd -Wait -PassThru -WindowStyle Hidden",
        "    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010 -or $p.ExitCode -eq 1605) {",
        "      # 1605 = « ce produit n'est pas installe » : le resultat voulu est atteint.",
        "      Remove-ItemProperty -Path $base -Name $d.marker -ErrorAction SilentlyContinue",
        "      Note ($d.marker + ' : DESINSTALLE (code ' + $p.ExitCode + ')')",
        "    } else {",
        "      # Le marqueur est CONSERVE : la prochaine execution reessaiera. L'effacer",
        "      # ferait oublier au poste qu'il reste un logiciel a retirer.",
        "      Note ($d.marker + ' : ECHEC desinstallation, code ' + $p.ExitCode)",
        "    }",
        "  } catch {",
        "    Note ($d.marker + ' : ECHEC — ' + $_.Exception.Message)",
        "  }",
        "}",
        "Note '--- Fin ---'",
    ]
    return "\r\n".join(lines) + "\r\n"

def main():
    guid = sys.argv[1]
    cfg = json.load(open(sys.argv[2], 'rb'))
    apps = cfg.get('apps', [])
    # Applications décochées : à retirer des postes où Bastion les avait installées.
    retraits = cfg.get('retraits', [])

    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable (%s)' % sysvol, file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    # Arborescence des scripts de démarrage.
    startup = os.path.join(sysvol, 'Machine', 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    if os.path.exists(ref):
        copy_ntacl(ref, os.path.join(sysvol, 'Machine'))
        copy_ntacl(ref, os.path.join(sysvol, 'Machine', 'Scripts'))
        copy_ntacl(ref, startup)

    # 1) PowerShell d'installation. Écrit par psfile : marque d'ordre d'octets UTF-8 +
    #    caractères sûrs. Sans cela, un simple tiret cadratin dans un message rendait
    #    le fichier inanalysable par PowerShell 5.1 (voir services/scripts/psfile.py).
    p_ps1 = psfile.ecrire_ps1(os.path.join(startup, 'bastion-apps.ps1'), ps1(apps, retraits))
    # 2) Lanceur .cmd (contourne la politique d'exécution PowerShell) : ASCII, sans marque.
    p_cmd = psfile.ecrire_cmd(os.path.join(startup, 'bastion-apps.cmd'),
                              '@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass '
                              '-File "%~dp0bastion-apps.ps1"\r\n')
    # 3) scripts.ini (UTF-16LE + BOM, comme GPMC) référencant le .cmd.
    p_ini = os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini')
    ini = "\r\n[Startup]\r\n0CmdLine=bastion-apps.cmd\r\n0Parameters=\r\n"
    with open(p_ini, 'wb') as w:
        w.write(b'\xff\xfe' + ini.encode('utf-16-le'))
    for f in (p_ps1, p_cmd, p_ini):
        os.chmod(f, 0o644)
        if os.path.exists(ref):
            copy_ntacl(ref, f)

    # ── Version + CSE Scripts via le module Samba ──
    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    # versionNumber : mot BAS = version ORDINATEUR (incrémentée), mot HAUT = version utilisateur.
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)

    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    if os.path.exists(ref):
        pass  # ref réécrit ci-dessus ; l'ACL a déjà été copiée initialement

    m = ldb.Message()
    m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement('[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL),
                                                       ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d apps=%d' % (newver, len(apps)))

if __name__ == '__main__':
    main()
