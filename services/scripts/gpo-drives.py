#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Génère/actualise la GPO « Bastion — Lecteurs réseau » : connexion automatique de
lecteurs réseau à l'ouverture de session (Group Policy Preferences → Drive Maps).
Les lettres/chemins sont fournis en JSON ; écrit User\\Preferences\\Drives\\Drives.xml
et pose le CSE « Drive Maps ».

Usage : gpo-drives.py <{GUID}> <drives.json>
drives.json : [{"letter":"Z","path":"\\\\bastion.pn.int\\Partage","label":"Partage"}, ...]
"""
import sys, os, json, uuid, datetime, subprocess
from xml.sax.saxutils import quoteattr

# CSE « Drive Maps » (GPP) + outil GPP.
DRIVES_CSE = '{5794DAFD-BE60-433F-88A2-1A31939AC01F}'
GPP_TOOL   = '{CEFFA6E2-E3BD-421B-852C-6F6A79A59BC1}'
NULL_GUID  = '{00000000-0000-0000-0000-000000000000}'

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

def drives_xml(drives, when):
    body = ['<?xml version="1.0" encoding="utf-8"?>',
            '<Drives clsid="{8FDDCC1A-0C3C-43cd-A6B4-71A6DF20DA8C}">']
    for d in drives:
        letter = (d.get('letter', 'Z') or 'Z')[0].upper()
        path = d.get('path', '')
        label = d.get('label', '')
        uid = '{%s}' % str(uuid.uuid4()).upper()
        props = ('<Properties action="U" thisDrive="NOCHANGE" allDrives="NOCHANGE" userName="" '
                 'path=%s label=%s persistent="1" useLetter="1" letter=%s/>' %
                 (quoteattr(path), quoteattr(label), quoteattr(letter)))
        body.append('<Drive clsid="{935D1B74-9CB8-4e3c-9914-7DD559B7A417}" name=%s status=%s image="2" '
                    'changed=%s uid=%s bypassErrors="1">%s</Drive>' %
                    (quoteattr(letter + ':'), quoteattr(letter + ':'), quoteattr(when), quoteattr(uid), props))
    body.append('</Drives>')
    return '\r\n'.join(body) + '\r\n'

def main():
    guid = sys.argv[1]
    drives = json.load(open(sys.argv[2], 'rb'))
    when = sys.argv[3] if len(sys.argv) > 3 else datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    base_dn = ','.join('DC=' + p for p in realm.split('.'))
    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    sam = '/var/lib/samba/private/sam.ldb'
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, base_dn)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable', file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    d = os.path.join(sysvol, 'User', 'Preferences', 'Drives')
    os.makedirs(d, exist_ok=True)
    for p in (os.path.join(sysvol, 'User'), os.path.join(sysvol, 'User', 'Preferences'), d):
        if os.path.exists(ref): copy_ntacl(ref, p)
    xml = os.path.join(d, 'Drives.xml')
    with open(xml, 'wb') as w:
        w.write(drives_xml(drives, when).encode('utf-8'))
    os.chmod(xml, 0o644)
    if os.path.exists(ref): copy_ntacl(ref, xml)

    # Version : incrémenter le mot HAUT (utilisateur). CSE utilisateur = Drive Maps.
    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    newver = ((((cur >> 16) & 0xFFFF) + 1) << 16) | (cur & 0xFFFF)
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    m = ldb.Message(); m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    ext = '[%s%s][%s%s]' % (NULL_GUID, DRIVES_CSE, DRIVES_CSE, GPP_TOOL)
    m['gPCUserExtensionNames'] = ldb.MessageElement(ext, ldb.FLAG_MOD_REPLACE, 'gPCUserExtensionNames')
    samdb.modify(m)
    print('OK version=%d drives=%d' % (newver, len(drives)))

if __name__ == '__main__':
    main()
