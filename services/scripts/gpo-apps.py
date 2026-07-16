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

def ps1(apps):
    """Construit le script PowerShell d'installation."""
    lines = [
        "$ErrorActionPreference = 'SilentlyContinue'",
        "$base = 'HKLM:\\Software\\Bastion\\Apps'",
        "New-Item -Path $base -Force | Out-Null",
        "$apps = @(",
    ]
    for a in apps:
        marker = a['marker'].replace("'", "")
        url = a['url'].replace("'", "")
        args = str(a.get('args', '')).replace("'", "''")
        msi = 'true' if a.get('msi') else 'false'
        lines.append("  @{ marker='%s'; url='%s'; args='%s'; msi=$%s }," % (marker, url, args, msi))
    lines += [
        ")",
        "foreach ($a in $apps) {",
        "  if (Get-ItemProperty -Path $base -Name $a.marker -ErrorAction SilentlyContinue) { continue }",
        "  $ext = if ($a.msi) { 'msi' } else { 'exe' }",
        "  $tmp = Join-Path $env:TEMP ('bastion_' + $a.marker + '.' + $ext)",
        "  try {",
        "    Invoke-WebRequest -Uri $a.url -OutFile $tmp -UseBasicParsing -TimeoutSec 180",
        "    if ($a.msi) { $p = Start-Process msiexec.exe -ArgumentList ('/i \"' + $tmp + '\" ' + $a.args) -Wait -PassThru }",
        "    else        { $p = Start-Process $tmp -ArgumentList $a.args -Wait -PassThru }",
        "    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010) { Set-ItemProperty -Path $base -Name $a.marker -Value 1 }",
        "    Remove-Item $tmp -Force -ErrorAction SilentlyContinue",
        "  } catch { }",
        "}",
    ]
    return "\r\n".join(lines) + "\r\n"

def main():
    guid = sys.argv[1]
    cfg = json.load(open(sys.argv[2], 'rb'))
    apps = cfg.get('apps', [])

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

    # 1) PowerShell d'installation.
    p_ps1 = os.path.join(startup, 'bastion-apps.ps1')
    with open(p_ps1, 'wb') as w:
        w.write(ps1(apps).encode('utf-8'))
    # 2) Lanceur .cmd (contourne la politique d'exécution PowerShell).
    p_cmd = os.path.join(startup, 'bastion-apps.cmd')
    cmd = "@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0bastion-apps.ps1\"\r\n"
    with open(p_cmd, 'wb') as w:
        w.write(cmd.encode('utf-8'))
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
