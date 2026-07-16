#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Applique des stratégies registre à une GPO Samba en écrivant directement le
fichier Registry.pol sur le SYSVOL local (root), puis met à jour GPT.INI et
l'objet LDAP (versionNumber + gPC*ExtensionNames). Contourne l'écriture SMB de
`samba-tool gpo load` (refusée), car `gpo create` écrit déjà le SYSVOL en local.

Usage : gpo-apply.py <{GUID}> <fichier.json>
Le JSON suit le format de `samba-tool gpo load` :
[{"keyname":..., "valuename":..., "class":"MACHINE|USER|BOTH", "type":"REG_DWORD|REG_SZ|REG_BINARY|REG_QWORD", "data":...}]
"""
import sys, os, json, struct, subprocess

REG_CSE = '{35378EAC-683F-11D2-A89A-00C04FBBCFA2}'   # Client Side Extension "Registry"
REG_TOOL = '{D02B1F72-3407-48AE-BA88-E8213C6761F1}'  # outil administratif standard
REGTYPE = {'REG_SZ': 1, 'REG_EXPAND_SZ': 2, 'REG_BINARY': 3, 'REG_DWORD': 4, 'REG_MULTI_SZ': 7, 'REG_QWORD': 11}

def sh(*a):
    return subprocess.run(a, capture_output=True, text=True)

def u16(s): return s.encode('utf-16-le')

def entry(key, val, typ, data):
    return (u16('[') + u16(key) + b'\x00\x00' + u16(';') + u16(val) + b'\x00\x00' +
            u16(';') + struct.pack('<I', typ) + u16(';') + struct.pack('<I', len(data)) +
            u16(';') + data + u16(']'))

def build_pol(pols, cls):
    body, n = b'', 0
    for p in pols:
        c = p.get('class', 'MACHINE').upper()
        if c != cls and c != 'BOTH':
            continue
        t = p['type'].upper(); typ = REGTYPE[t]; d = p['data']
        if t == 'REG_DWORD':    data = struct.pack('<I', int(d))
        elif t == 'REG_QWORD':  data = struct.pack('<Q', int(d))
        elif t == 'REG_BINARY': data = bytes(d)
        else:                   data = u16(str(d)) + b'\x00\x00'
        body += entry(p['keyname'], p['valuename'], typ, data)
        n += 1
    return (b'PReg' + struct.pack('<I', 1) + body) if n else None

def copy_ntacl(src, dst):
    """Recopie l'ACL NT (xattr security.NTACL) d'un fichier de référence (best-effort)."""
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
    jsonfile = sys.argv[2]
    realm = sh('testparm', '-s', '--parameter-name=realm').stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: GPO SYSVOL introuvable (%s)' % sysvol, file=sys.stderr); sys.exit(1)

    pols = json.load(open(jsonfile, 'rb'))
    ref_acl = os.path.join(sysvol, 'GPT.INI')   # ACL de référence posée par gpo create

    changed_m = changed_u = False
    for cls, sub in [('MACHINE', 'Machine'), ('USER', 'User')]:
        pol = build_pol(pols, cls)
        if pol is None:
            continue
        d = os.path.join(sysvol, sub)
        os.makedirs(d, exist_ok=True)
        if os.path.exists(ref_acl):
            copy_ntacl(ref_acl, d)
        f = os.path.join(d, 'Registry.pol')
        with open(f, 'wb') as w:
            w.write(pol)
        os.chmod(f, 0o644)
        if os.path.exists(ref_acl):
            copy_ntacl(ref_acl, f)
        if cls == 'MACHINE': changed_m = True
        else: changed_u = True

    if not (changed_m or changed_u):
        print('ERROR: aucune stratégie', file=sys.stderr); sys.exit(1)

    # ── LDAP via le module Python Samba (accès local root, sans binaire externe) ──
    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)

    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    # Version : machine = 16 bits hauts, utilisateur = 16 bits bas.
    # versionNumber : mot HAUT (bits 16-31) = version UTILISATEUR, mot BAS = version ORDINATEUR.
    uv = (cur >> 16) & 0xFFFF
    mv = cur & 0xFFFF
    if changed_m: mv += 1
    if changed_u: uv += 1
    newver = (uv << 16) | mv

    # GPT.INI (doit refléter versionNumber)
    with open(os.path.join(sysvol, 'GPT.INI'), 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    if os.path.exists(ref_acl):
        copy_ntacl(ref_acl, os.path.join(sysvol, 'GPT.INI'))

    m = ldb.Message()
    m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    extval = '[%s%s]' % (REG_CSE, REG_TOOL)
    if changed_m:
        m['gPCMachineExtensionNames'] = ldb.MessageElement(extval, ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    if changed_u:
        m['gPCUserExtensionNames'] = ldb.MessageElement(extval, ldb.FLAG_MOD_REPLACE, 'gPCUserExtensionNames')
    samdb.modify(m)

    print('OK version=%d machine=%s user=%s' % (newver, changed_m, changed_u))

if __name__ == '__main__':
    main()
