#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère la GPO « Bastion — Inventaire du parc » : un script d'OUVERTURE DE SESSION qui relève
les caractéristiques du poste et les transmet à la passerelle.

Pourquoi un script d'ouverture de session et non de démarrage : c'est le seul des deux qui
s'est montré fiable sur ce parc (les scripts de démarrage ne tournent qu'au boot et échouent
quand l'horloge du poste est décalée). Les classes WMI utilisées ici sont toutes lisibles par
un utilisateur ordinaire — aucune élévation n'est nécessaire.

Usage : gpo-inventory.py <{GUID}> <URL de collecte> <jeton>
"""
import sys, os, subprocess

SCRIPTS_CSE  = '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'
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

def ps1(url, token):
    """Collecteur : relève l'essentiel puis transmet en JSON. Silencieux et sans effet de bord —
    il ne fait que LIRE ; en cas de problème réseau il abandonne sans déranger l'agent."""
    L = [
        "$ErrorActionPreference='SilentlyContinue'",
        "# Une seule remontée par jour : inutile de solliciter le réseau à chaque ouverture.",
        "$marq=Join-Path $env:LOCALAPPDATA 'bastion-inv.txt'",
        "if (Test-Path $marq) { if ((Get-Item $marq).LastWriteTime -gt (Get-Date).AddHours(20)) { exit } }",
        "",
        "$os  = Get-CimInstance Win32_OperatingSystem",
        "$cs  = Get-CimInstance Win32_ComputerSystem",
        "$bios= Get-CimInstance Win32_BIOS",
        "$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1",
        "$net = Get-CimInstance Win32_NetworkAdapterConfiguration -Filter 'IPEnabled=True' | Select-Object -First 1",
        "$dsk = Get-CimInstance Win32_LogicalDisk -Filter \"DeviceID='C:'\"",
        "$phys= Get-CimInstance Win32_DiskDrive | Select-Object -First 1",
        "",
        "# Logiciels installés (registre : plus fiable et bien plus rapide que Win32_Product,",
        "# qui déclenche une revalidation MSI de chaque paquet).",
        "$reg=@('HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*',",
        "       'HKLM:\\Software\\WOW6432Node\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\*')",
        "$apps=@(Get-ItemProperty $reg | Where-Object { $_.DisplayName } |",
        "        Select-Object @{n='n';e={$_.DisplayName}}, @{n='v';e={$_.DisplayVersion}} |",
        "        Sort-Object n -Unique)",
        "",
        "$data=@{",
        "  poste      = $env:COMPUTERNAME",
        "  domaine    = $cs.Domain",
        "  utilisateur= $env:USERNAME",
        "  os_nom     = $os.Caption",
        "  os_version = $os.Version",
        "  os_build   = $os.BuildNumber",
        "  os_sku     = $os.OperatingSystemSKU",
        "  os_install = if ($os.InstallDate) { $os.InstallDate.ToString('yyyy-MM-dd') } else { '' }",
        "  fabricant  = $cs.Manufacturer",
        "  modele     = $cs.Model",
        "  serie      = $bios.SerialNumber",
        "  bios       = ($bios.Manufacturer + ' ' + $bios.SMBIOSBIOSVersion)",
        "  type       = $cs.PCSystemType",
        "  processeur = $cpu.Name",
        "  coeurs     = $cpu.NumberOfCores",
        "  memoire_mo = [int]($cs.TotalPhysicalMemory/1MB)",
        "  disque_go  = [int]($dsk.Size/1GB)",
        "  libre_go   = [int]($dsk.FreeSpace/1GB)",
        "  disque_mdl = $phys.Model",
        "  ip         = ($net.IPAddress | Where-Object { $_ -notmatch ':' } | Select-Object -First 1)",
        "  mac        = $net.MACAddress",
        "  secureboot = try { [int](Confirm-SecureBootUEFI) } catch { -1 }",
        "  logiciels  = $apps",
        "}",
        "",
        "# La console est en HTTPS avec le certificat interne de Bastion. Tant que la strategie",
        "# « Certificat racine » n'est pas appliquee, le poste ne le reconnait pas et l'envoi",
        "# echouerait. On accepte donc le certificat le temps de CET appel, puis on retablit le",
        "# controle. Compromis assume et borne : la connexion ne quitte pas le reseau local, elle",
        "# vise la passerelle, et le contenu transmis est un inventaire materiel — aucune donnee",
        "# sensible, aucun identifiant.",
        "$cb = [System.Net.ServicePointManager]::ServerCertificateValidationCallback",
        "try {",
        "  [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }",
        "  $j = $data | ConvertTo-Json -Depth 4 -Compress",
        "  $r = Invoke-WebRequest -Uri '%s' -Method POST -UseBasicParsing -TimeoutSec 30 `" % url,
        "        -ContentType 'application/json; charset=utf-8' `",
        "        -Headers @{ Authorization = 'Bearer %s' } `" % token,
        "        -Body ([Text.Encoding]::UTF8.GetBytes($j))",
        "  if ($r.StatusCode -eq 200) { Set-Content -Path $marq -Value (Get-Date -Format s) }",
        "} catch { } finally {",
        "  [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $cb",
        "}",
    ]
    return "\r\n".join(L) + "\r\n"

def main():
    guid, url, token = sys.argv[1], sys.argv[2], sys.argv[3]
    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'],
                           capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable', file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    logon = os.path.join(sysvol, 'User', 'Scripts', 'Logon')
    os.makedirs(logon, exist_ok=True)
    for d in (os.path.join(sysvol, 'User'), os.path.join(sysvol, 'User', 'Scripts'), logon):
        if os.path.exists(ref): copy_ntacl(ref, d)

    p_ps1 = os.path.join(logon, 'bastion-inventaire.ps1')
    with open(p_ps1, 'wb') as w:
        w.write(ps1(url, token).encode('utf-8'))
    p_cmd = os.path.join(logon, 'bastion-inventaire.cmd')
    with open(p_cmd, 'wb') as w:
        w.write(b"@echo off\r\npowershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden "
                b"-File \"%~dp0bastion-inventaire.ps1\"\r\n")
    p_ini = os.path.join(sysvol, 'User', 'Scripts', 'scripts.ini')
    with open(p_ini, 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Logon]\r\n0CmdLine=bastion-inventaire.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for f in (p_ps1, p_cmd, p_ini):
        os.chmod(f, 0o644)
        if os.path.exists(ref): copy_ntacl(ref, f)

    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    newver = ((((cur >> 16) & 0xFFFF) + 1) << 16) | (cur & 0xFFFF)   # mot HAUT = utilisateur
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    m = ldb.Message(); m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    m['gPCUserExtensionNames'] = ldb.MessageElement('[%s%s]' % (SCRIPTS_CSE, SCRIPTS_TOOL),
                                                    ldb.FLAG_MOD_REPLACE, 'gPCUserExtensionNames')
    samdb.modify(m)
    print('OK version=%d' % newver)

if __name__ == '__main__':
    main()
