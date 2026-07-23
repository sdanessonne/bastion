#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère/actualise la GPO « Bastion — Chiffrement BitLocker ». Elle combine :
  1. des STRATÉGIES REGISTRE (FVE) qui activent la sauvegarde de la clé de récupération
     dans l'Active Directory ;
  2. un SCRIPT DE DÉMARRAGE (SYSTEM) qui, sur un poste doté d'un TPM prêt, ajoute une clé de
     récupération, la SAUVEGARDE DANS L'AD, puis active BitLocker (déverrouillage transparent
     par le TPM). Sans TPM prêt, le script s'abstient (éviter une saisie au démarrage) ; sur
     une édition sans BitLocker (Familiale), il s'abstient aussi. Un marqueur registre évite de
     recommencer à chaque démarrage.

La clé de récupération est donc TOUJOURS séquestrée dans l'AD AVANT que le disque ne soit
chiffré — on ne peut pas se retrouver avec un poste chiffré dont la clé serait introuvable.

Usage : gpo-bitlocker.py <{GUID}>
"""
import sys, os, struct, subprocess

REG_CSE      = '{35378EAC-683F-11D2-A89A-00C04FBBCFA2}'   # Client Side Extension « Registre »
REG_TOOL     = '{D02B1F72-3407-48AE-BA88-E8213C6761F1}'
SCRIPTS_CSE  = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'   # CSE « Scripts » (démarrage/arrêt)
SCRIPTS_TOOL = '{40B6664F-4972-11D1-A7CA-0000F87571E3}'

# ── Stratégies registre FVE : activer le séquestre AD (OS + disques fixes) ──────────
# On reste minimal et SÛR : on active la sauvegarde AD sans « exiger » (qui bloquerait
# l'activation si l'AD était momentanément injoignable). Le script fait de toute façon une
# sauvegarde EXPLICITE de la clé dans l'AD avant de chiffrer.
FVE_KEY = 'Software\\Policies\\Microsoft\\FVE'
FVE = [
    ('OSRecovery', 1), ('OSActiveDirectoryBackup', 1), ('OSActiveDirectoryInfoToStore', 1),
    ('FDVRecovery', 1), ('FDVActiveDirectoryBackup', 1), ('FDVActiveDirectoryInfoToStore', 1),
]

STARTUP_PS1 = r"""$ErrorActionPreference = 'SilentlyContinue'
$mk = 'HKLM:\Software\Bastion'
New-Item -Path $mk -Force | Out-Null
if (Get-ItemProperty -Path $mk -Name 'BitLockerDone' -ErrorAction SilentlyContinue) { exit }
$drv = $env:SystemDrive
$bv = Get-BitLockerVolume -MountPoint $drv -ErrorAction SilentlyContinue
if ($null -eq $bv) { exit }                       # BitLocker indisponible (edition Familiale)
if ($bv.ProtectionStatus -eq 'On' -or $bv.VolumeStatus -match 'Encrypt') {
    Set-ItemProperty -Path $mk -Name 'BitLockerDone' -Value 1; exit
}
$tpm = Get-Tpm -ErrorAction SilentlyContinue
if (-not ($tpm -and $tpm.TpmPresent -and $tpm.TpmReady)) { exit }   # sans TPM pret : on n'active pas
try {
    # 1) Cle de recuperation + SAUVEGARDE DANS L'AD (avant tout chiffrement).
    Add-BitLockerKeyProtector -MountPoint $drv -RecoveryPasswordProtector -ErrorAction Stop | Out-Null
    $bv = Get-BitLockerVolume -MountPoint $drv
    $rp = $bv.KeyProtector | Where-Object { $_.KeyProtectorType -eq 'RecoveryPassword' } | Select-Object -First 1
    if ($rp) { Backup-BitLockerKeyProtector -MountPoint $drv -KeyProtectorId $rp.KeyProtectorId | Out-Null }
    # 2) Protecteur TPM (deverrouillage transparent) + activation du chiffrement.
    Add-BitLockerKeyProtector -MountPoint $drv -TpmProtector -ErrorAction SilentlyContinue | Out-Null
    Enable-BitLocker -MountPoint $drv -EncryptionMethod XtsAes256 -UsedSpaceOnly -SkipHardwareTest -RecoveryPasswordProtector -ErrorAction SilentlyContinue | Out-Null
    Set-ItemProperty -Path $mk -Name 'BitLockerDone' -Value 1
} catch { }
"""


def u16(s): return s.encode('utf-16-le')


def preg_entry(key, val, typ, data):
    return (u16('[') + u16(key) + b'\x00\x00' + u16(';') + u16(val) + b'\x00\x00' +
            u16(';') + struct.pack('<I', typ) + u16(';') + struct.pack('<I', len(data)) +
            u16(';') + data + u16(']'))


def build_fve_pol():
    body = b''
    for name, value in FVE:
        body += preg_entry(FVE_KEY, name, 4, struct.pack('<I', value))   # 4 = REG_DWORD
    return b'PReg' + struct.pack('<I', 1) + body


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
    guid = sys.argv[1]
    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable (%s)' % sysvol, file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    # 1) Registry.pol (Machine) — stratégies FVE.
    mdir = os.path.join(sysvol, 'Machine')
    os.makedirs(mdir, exist_ok=True)
    if os.path.exists(ref):
        copy_ntacl(ref, mdir)
    p_pol = os.path.join(mdir, 'Registry.pol')
    with open(p_pol, 'wb') as w:
        w.write(build_fve_pol())

    # 2) Script de démarrage.
    startup = os.path.join(mdir, 'Scripts', 'Startup')
    os.makedirs(startup, exist_ok=True)
    p_ps1 = os.path.join(startup, 'bastion-bitlocker.ps1')
    with open(p_ps1, 'wb') as w:
        w.write(STARTUP_PS1.replace('\n', '\r\n').encode('utf-8'))
    p_cmd = os.path.join(startup, 'bastion-bitlocker.cmd')
    with open(p_cmd, 'wb') as w:
        w.write(("@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0bastion-bitlocker.ps1\"\r\n").encode('utf-8'))
    p_ini = os.path.join(mdir, 'Scripts', 'scripts.ini')
    with open(p_ini, 'wb') as w:
        w.write(b'\xff\xfe' + ("\r\n[Startup]\r\n0CmdLine=bastion-bitlocker.cmd\r\n0Parameters=\r\n").encode('utf-16-le'))
    for f in (p_pol, p_ps1, p_cmd, p_ini):
        os.chmod(f, 0o644)
        if os.path.exists(ref):
            copy_ntacl(ref, f)
    for d in (os.path.join(mdir, 'Scripts'), startup):
        if os.path.exists(ref):
            copy_ntacl(ref, d)

    # 3) Version + les DEUX CSE (Registre + Scripts) via le module Samba.
    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    newver = (((cur >> 16) & 0xFFFF) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)   # incrémente le mot ORDINATEUR
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    ext = '[%s%s][%s%s]' % (REG_CSE, REG_TOOL, SCRIPTS_CSE, SCRIPTS_TOOL)
    m = ldb.Message()
    m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCMachineExtensionNames'] = ldb.MessageElement(ext, ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d bitlocker' % newver)


if __name__ == '__main__':
    main()
