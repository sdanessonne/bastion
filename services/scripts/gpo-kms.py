#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère la GPO « Bastion — Activation Windows/Office » : script de DÉMARRAGE (SYSTEM)
qui, si le poste n'est PAS déjà activé, pose la clé KMS générique (GVLK) selon
l'édition, pointe vers le serveur KMS de la passerelle et active Windows ; puis
active Office (ospp.vbs) contre le même serveur. Garde-fou : ne touche pas un poste
déjà activé (licence OEM/numérique préservée).

Usage : gpo-kms.py <{GUID}> <GW_IP>
"""
import sys, os, subprocess

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

def ps1(gw):
    L = [
        "$ErrorActionPreference='SilentlyContinue'",
        "$kms='%s:1688'" % gw,
        "$slmgr=\"$env:windir\\System32\\slmgr.vbs\"",
        "# Ne rien faire si Windows est déjà activé (préserve OEM/numérique).",
        "$win=Get-CimInstance SoftwareLicensingProduct -Filter \"ApplicationID='55c92734-d682-4d71-983e-d6ec3f16059f' AND PartialProductKey IS NOT NULL\" | Where-Object {$_.LicenseStatus -eq 1}",
        "if (-not $win) {",
        "# Edition determinee par son CODE NUMERIQUE et non par le libelle : sur un Windows",
        "# FRANCAIS le libelle vaut « Entreprise » et « Professionnel » — un test sur",
        "# 'Enterprise' ne correspondait donc a rien, et un poste Entreprise recevait la cle Pro.",
        "  $sku=(Get-CimInstance Win32_OperatingSystem).OperatingSystemSKU",
        "  $gvlk=$null",
        "  if ($sku -in 4,27,125) { $gvlk='NPPR9-FWDCX-D2C8J-H872K-2YT43' }",          # Entreprise
        "  elseif ($sku -in 121,122) { $gvlk='NW6C2-QMPVW-D7KKK-3GKT6-VCFB2' }",       # Education
        "  elseif ($sku -in 48,49) { $gvlk='W269N-WFGWX-YVC9B-4J6C9-T83GX' }",         # Professionnel
        "  if ($gvlk) { cscript //nologo $slmgr /ipk $gvlk | Out-Null }",
        "  cscript //nologo $slmgr /skms $kms | Out-Null",
        "  cscript //nologo $slmgr /ato | Out-Null",
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

    with open(os.path.join(startup, 'bastion-activate.ps1'), 'wb') as w:
        w.write(ps1(gw).encode('utf-8'))
    with open(os.path.join(startup, 'bastion-activate.cmd'), 'wb') as w:
        w.write(b"@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0bastion-activate.ps1\"\r\n")
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
