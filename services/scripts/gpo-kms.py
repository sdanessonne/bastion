#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère la GPO « Bastion — Activation Windows/Office » : script de DÉMARRAGE (SYSTEM)
qui pose la clé KMS générique (GVLK) correspondant à l'édition, pointe vers le
serveur KMS de la passerelle et active Windows ; puis active Office (ospp.vbs)
contre le même serveur. Garde-fou : ne touche pas un poste déjà activé — sauf
montée d'édition explicitement demandée (voir plus bas).

Usage : gpo-kms.py <{GUID}> <GW_IP> [1 = monter Professionnel → Entreprise]
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

GVLK_ENT = 'NPPR9-FWDCX-D2C8J-H872K-2YT43'   # Entreprise
GVLK_EDU = 'NW6C2-QMPVW-D7KKK-3GKT6-VCFB2'   # Education
GVLK_PRO = 'W269N-WFGWX-YVC9B-4J6C9-T83GX'   # Professionnel


def ps1(gw, monter=False):
    L = [
        "$ErrorActionPreference='SilentlyContinue'",
        "$kms='%s:1688'" % gw,
        "$slmgr=\"$env:windir\\System32\\slmgr.vbs\"",
        "$APPWIN='55c92734-d682-4d71-983e-d6ec3f16059f'",
        "",
        "# ── LE COMPTE RENDU N'EST PAS UN LUXE ────────────────────────────────────",
        "# Une activation qui echoue ne se voit NULLE PART : le poste reste dans son",
        "# edition d'origine et personne ne l'apprend. On ecrit donc deux traces — un",
        "# journal lisible sur le poste, et une cle de registre que l'inventaire remonte",
        "# a la console. Sans elles, le seul moyen de savoir etait d'aller voir.",
        "$jrn=\"$env:windir\\Temp\\bastion-activation.log\"",
        "function Note($m) { try { Add-Content -Path $jrn -Encoding UTF8 -Value ('{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) } catch { } }",
        "function Etat($e, $d) { try {",
        "  New-Item -Path 'HKLM:\\Software\\Bastion\\Activation' -Force | Out-Null",
        "  Set-ItemProperty 'HKLM:\\Software\\Bastion\\Activation' -Name 'etat'   -Value ([string]$e)",
        "  Set-ItemProperty 'HKLM:\\Software\\Bastion\\Activation' -Name 'detail' -Value ([string]$d)",
        "  Set-ItemProperty 'HKLM:\\Software\\Bastion\\Activation' -Name 'date'   -Value (Get-Date -Format s)",
        "} catch { } }",
        "function Sortie($c) { ($c -replace '\\s+', ' ').Trim() }",
        "",
        "# Edition determinee par son CODE NUMERIQUE et non par le libelle : sur un Windows",
        "# FRANCAIS le libelle vaut « Entreprise » et « Professionnel » — un test sur",
        "# 'Enterprise' ne correspondait donc a rien, et un poste Entreprise recevait la cle Pro.",
        "$sku=[int](Get-CimInstance Win32_OperatingSystem).OperatingSystemSKU",
        "$actif=[bool](Get-CimInstance SoftwareLicensingProduct -Filter \"ApplicationID='$APPWIN' AND PartialProductKey IS NOT NULL\" | Where-Object { $_.LicenseStatus -eq 1 })",
        "Note ('--- demarrage : SKU={0}, deja active={1}' -f $sku, $actif)",
        "",
        "$gvlk=$null; $cible=''; $montee=$false",
        "if     ($sku -in 4,27,125) { $gvlk='%s'; $cible='Entreprise' }" % GVLK_ENT,
        "elseif ($sku -in 121,122)  { $gvlk='%s'; $cible='Education' }" % GVLK_EDU,
    ]
    if monter:
        # Montée d'édition Professionnel → Entreprise, demandée explicitement depuis la
        # console. La clé Entreprise est posée DIRECTEMENT : sur Windows 10 1703+ et
        # Windows 11, c'est la pose de la clé qui déclenche le changement d'édition.
        # Suppose que l'organisation détienne les droits Entreprise (contrat en volume) —
        # question de licence, pas de technique.
        L += [
            "elseif ($sku -in 48,49)    { $gvlk='%s'; $cible='Entreprise (montee depuis Professionnel)'; $montee=$true }" % GVLK_ENT,
        ]
    else:
        L += [
            "elseif ($sku -in 48,49)    { $gvlk='%s'; $cible='Professionnel' }" % GVLK_PRO,
        ]
    L += [
        "else { Note ('edition non geree (SKU {0}) : rien de fait' -f $sku); Etat 'ignore' ('edition non geree, SKU ' + $sku) }",
        "",
        "# ── LE GARDE-FOU, ET SON EXCEPTION ───────────────────────────────────────",
        "# On ne touche pas a un Windows deja active : sa licence OEM ou numerique est",
        "# preservee. UNE exception, la montee d'edition demandee explicitement — un poste",
        "# sorti d'usine est TOUJOURS deja active en Professionnel, et s'arreter la revenait",
        "# a n'en monter aucun. C'etait exactement la panne : le script sautait tout le bloc",
        "# et ne laissait aucune trace de son abstention.",
        "if ($gvlk -and $actif -and -not $montee) {",
        "  Note ('deja active en ' + $cible + ' : rien a faire')",
        "  Etat 'ok-existant' ('deja active — ' + $cible)",
        "} elseif ($gvlk) {",
        "  Note ('cible : ' + $cible)",
        "  # L'ordre compte : le serveur KMS doit etre connu AVANT toute tentative d'activation.",
        "  Note ('skms : ' + (Sortie (& cscript //nologo $slmgr /skms $kms 2>&1 | Out-String)))",
        "  Note ('ipk  : ' + (Sortie (& cscript //nologo $slmgr /ipk $gvlk 2>&1 | Out-String)))",
        "  Start-Sleep -Seconds 5",
        "  Note ('ato  : ' + (Sortie (& cscript //nologo $slmgr /ato 2>&1 | Out-String)))",
        "",
        "  $sku2=[int](Get-CimInstance Win32_OperatingSystem).OperatingSystemSKU",
        "  $ok=[bool](Get-CimInstance SoftwareLicensingProduct -Filter \"ApplicationID='$APPWIN' AND PartialProductKey IS NOT NULL\" | Where-Object { $_.LicenseStatus -eq 1 })",
        "  if ($ok -and (-not $montee -or $sku2 -ne $sku)) {",
        "    Note 'RESULTAT : active'; Etat 'active' ('{0} (SKU {1})' -f $cible, $sku2)",
        "  } elseif ($montee) {",
        "    # Le changement d'edition ne prend effet qu'au redemarrage suivant ; le script",
        "    # repassera alors en Entreprise NON activee et terminera le travail tout seul.",
        "    Note 'RESULTAT : cle Entreprise posee, redemarrage necessaire'",
        "    Etat 'redemarrage' 'montee posee — redemarrage necessaire'",
        "  } else {",
        "    Note 'RESULTAT : ECHEC — voir les lignes ipk/ato ci-dessus'",
        "    Etat 'echec' ('non active — ' + $cible)",
        "  }",
        "}",
        "# Office (ospp.vbs) : pointer le serveur KMS et activer.",
        "$off=@(\"$env:ProgramFiles\\Microsoft Office\\Office16\\ospp.vbs\",\"${env:ProgramFiles(x86)}\\Microsoft Office\\Office16\\ospp.vbs\",\"$env:ProgramFiles\\Microsoft Office\\Office15\\ospp.vbs\",\"${env:ProgramFiles(x86)}\\Microsoft Office\\Office15\\ospp.vbs\")",
        "foreach ($p in $off) { if (Test-Path $p) {",
        "  cscript //nologo $p /sethst:%s | Out-Null" % gw,
        "  cscript //nologo $p /setprt:1688 | Out-Null",
        "  cscript //nologo $p /act | Out-Null",
        "  break } }",
    ]
    return "\r\n".join(L) + "\r\n"

def main():
    guid = sys.argv[1]; gw = sys.argv[2]
    monter = len(sys.argv) > 3 and sys.argv[3] == '1'
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

    psfile.ecrire_ps1(os.path.join(startup, 'bastion-activate.ps1'), ps1(gw, monter))
    psfile.ecrire_cmd(os.path.join(startup, 'bastion-activate.cmd'),
                      '@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass '
                      '-File "%~dp0bastion-activate.ps1"\r\n')
    with open(os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini'), 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Startup]\r\n0CmdLine=bastion-activate.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in ('Startup/bastion-activate.ps1', 'Startup/bastion-activate.cmd', 'scripts.ini'):
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
    # versionNumber : mot BAS = version ORDINATEUR (incrémentée), mot HAUT = version utilisateur.
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)
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
