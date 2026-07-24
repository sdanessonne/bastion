#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère la GPO « Bastion — Recaler l'heure au démarrage » : script de DÉMARRAGE (SYSTEM)
qui, à CHAQUE boot du poste, (ré)applique la config W32Time (source = passerelle en NTP,
correction d'écart ILLIMITÉE) et FORCE une resynchronisation. Ceinture-bretelles contre les
postes (souvent des VM) dont l'horloge se décale au démarrage et ne se resynchronise pas :
Kerberos et les GPO (dont les lecteurs réseau) échouent tant que l'heure est fausse.

Usage : gpo-timesync.py <{GUID}> <GW_IP>
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
        "$gw='%s'" % gw,
        "# Autoriser W32Time a corriger N'IMPORTE QUEL ecart (cle SERVICE, celle qu'il lit vraiment).",
        "$cfg='HKLM:\\SYSTEM\\CurrentControlSet\\Services\\W32Time\\Config'",
        "Set-ItemProperty -Path $cfg -Name MaxPosPhaseCorrection -Value 0xFFFFFFFF -Type DWord",
        "Set-ItemProperty -Path $cfg -Name MaxNegPhaseCorrection -Value 0xFFFFFFFF -Type DWord",
        "# Pointer la passerelle en NTP simple + service en demarrage automatique.",
        "& w32tm /config /manualpeerlist:\"$gw,0x9\" /syncfromflags:manual /update | Out-Null",
        "Set-Service w32time -StartupType Automatic",
        "Start-Service w32time",
        "# Laisser le reseau monter, puis FORCER la resynchro (NTP simple : marche meme tres decale).",
        "Start-Sleep -Seconds 8",
        "& w32tm /resync /rediscover /force | Out-Null",
    ]
    return "\r\n".join(L) + "\r\n"

def main():
    guid = sys.argv[1]; gw = sys.argv[2] if len(sys.argv) > 2 else '192.168.182.1'
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

    with open(os.path.join(startup, 'bastion-timesync.ps1'), 'wb') as w:
        w.write(ps1(gw).encode('utf-8'))
    with open(os.path.join(startup, 'bastion-timesync.cmd'), 'wb') as w:
        w.write(b"@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0bastion-timesync.ps1\"\r\n")
    with open(os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini'), 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Startup]\r\n0CmdLine=bastion-timesync.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in ('Startup/bastion-timesync.ps1', 'Startup/bastion-timesync.cmd', 'scripts.ini'):
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
