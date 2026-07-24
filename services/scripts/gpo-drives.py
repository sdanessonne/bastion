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
import sys, os, json, uuid, datetime, subprocess, struct
from xml.sax.saxutils import quoteattr

# CSE « Drive Maps » (GPP) + outil GPP.
DRIVES_CSE = '{5794DAFD-BE60-433F-88A2-1A31939AC01F}'
GPP_TOOL   = '{CEFFA6E2-E3BD-421B-852C-6F6A79A59BC1}'
NULL_GUID  = '{00000000-0000-0000-0000-000000000000}'
# CSE « Registre » (paramètres MACHINE) — pour fiabiliser le traitement des préférences GPP.
REG_CSE    = '{35378EAC-683F-11D2-A89A-00C04FBBCFA2}'
REG_TOOL   = '{D02B1F72-3407-48AE-BA88-E8213C6761F1}'

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

def _u16(s):
    return s.encode('utf-16-le')

def _reg_entry(key, val, typ, data):
    # Une entrée PReg : [key\0;value\0;type;taille;données]
    return (_u16('[') + _u16(key) + b'\x00\x00' + _u16(';') + _u16(val) + b'\x00\x00' +
            _u16(';') + struct.pack('<I', typ) + _u16(';') + struct.pack('<I', len(data)) +
            _u16(';') + data + _u16(']'))

def reliability_pol():
    """Registry.pol MACHINE qui force le CSE « Drive Maps » à RE-JOUER les préférences GPP
    à chaque ouverture de session / rafraîchissement, MÊME si la GPO n'a pas changé
    (NoGPOListChanges=0), et en tâche de fond (NoBackgroundPolicy=0). Sans cela, un échec
    ponctuel du 1er montage (réseau/partage pas encore prêt) n'est JAMAIS réessayé — cause
    classique de « lecteurs qui ne remontent pas » alors que la GPO est correcte."""
    key = 'Software\\Policies\\Microsoft\\Windows\\Group Policy\\' + DRIVES_CSE
    body = (_reg_entry(key, 'NoGPOListChanges', 4, struct.pack('<I', 0)) +
            _reg_entry(key, 'NoBackgroundPolicy', 4, struct.pack('<I', 0)))
    return b'PReg' + struct.pack('<I', 1) + body

def dc_fqdn(realm):
    """Nom de SERVEUR du contrôleur de domaine (netbios.realm). Un partage ordinaire n'est PAS
    dans l'espace de noms DFS du domaine : \\\\domaine\\Partage renvoie « Élément introuvable ».
    Il faut le nom du SERVEUR — d'où cette résolution du FQDN du DC."""
    nb = subprocess.run(['testparm', '-s', '--parameter-name=netbios name'],
                        capture_output=True, text=True).stdout.strip() or 'dc'
    return (nb + '.' + realm).lower()

def normalize_unc(path, realm, dc):
    """Réécrit \\\\<nom-de-domaine>\\Partage en \\\\<nom-de-serveur>\\Partage. Le nom de domaine
    ne dessert que SYSVOL/NETLOGON (racines DFS) ; un partage ordinaire atteint par le nom de
    domaine échoue « Élément introuvable » / « Fonction incorrecte » côté Drive Maps."""
    if not path.startswith('\\\\'):
        return path
    rest = path[2:]
    i = rest.find('\\')
    host = rest if i < 0 else rest[:i]
    tail = '' if i < 0 else rest[i:]
    if host.lower() == realm.lower():
        host = dc
    return '\\\\' + host + tail

def drives_xml(drives, when):
    body = ['<?xml version="1.0" encoding="utf-8"?>',
            '<Drives clsid="{8FDDCC1A-0C3C-43cd-A6B4-71A6DF20DA8C}">']
    for d in drives:
        letter = (d.get('letter', 'Z') or 'Z')[0].upper()
        path = d.get('path', '')
        label = d.get('label', '')
        uid = '{%s}' % str(uuid.uuid4()).upper()
        # action="R" (Replace = SUPPRIME puis RECRÉE le lecteur) et NON "U" (Update) : le montage
        # manuel « net use » fonctionne, mais l'action Update du CSE Drive Maps échoue « Fonction
        # incorrecte » (0x1) — Replace fait exactement ce qu'un net use propre fait, et corrige le cas.
        props = ('<Properties action="R" thisDrive="NOCHANGE" allDrives="NOCHANGE" userName="" '
                 'path=%s label=%s persistent="1" useLetter="1" letter=%s/>' %
                 (quoteattr(path), quoteattr(label), quoteattr(letter)))
        body.append('<Drive clsid="{935D1B74-9CB8-4e3c-9914-7DD559B7A417}" name=%s status=%s image="2" '
                    'changed=%s uid=%s bypassErrors="1" removePolicy="0">%s</Drive>' %
                    (quoteattr(letter + ':'), quoteattr(letter + ':'), quoteattr(when), quoteattr(uid), props))
    body.append('</Drives>')
    return '\r\n'.join(body) + '\r\n'

def main():
    guid = sys.argv[1]
    drives = json.load(open(sys.argv[2], 'rb'))
    when = sys.argv[3] if len(sys.argv) > 3 else datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')

    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'], capture_output=True, text=True).stdout.strip().lower()
    # Réécriture nom-de-domaine -> nom-de-serveur pour chaque chemin UNC (voir normalize_unc).
    dc = dc_fqdn(realm)
    for _d in drives:
        _d['path'] = normalize_unc(_d.get('path', ''), realm, dc)
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

    # Côté MACHINE : Registry.pol de fiabilisation du traitement GPP (reprocessing).
    md = os.path.join(sysvol, 'Machine')
    os.makedirs(md, exist_ok=True)
    if os.path.exists(ref): copy_ntacl(ref, md)
    mpol = os.path.join(md, 'Registry.pol')
    with open(mpol, 'wb') as w:
        w.write(reliability_pol())
    os.chmod(mpol, 0o644)
    if os.path.exists(ref): copy_ntacl(ref, mpol)

    # Version : incrémenter le mot HAUT (utilisateur = Drive Maps) ET le mot BAS
    # (ordinateur = Registre de fiabilisation), sinon le poste ne relit pas la moitié machine.
    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    samdb = SamDB(url=sam, session_info=system_session(), lp=lp)
    res = samdb.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['versionNumber'])
    cur = int(str(res[0]['versionNumber'][0])) if (res and 'versionNumber' in res[0]) else 0
    newver = ((((cur >> 16) & 0xFFFF) + 1) << 16) | (((cur & 0xFFFF) + 1) & 0xFFFF)
    with open(ref, 'wb') as w:
        w.write(('[General]\r\nVersion=%d\r\n' % newver).encode('ascii'))
    m = ldb.Message(); m.dn = ldb.Dn(samdb, gpo_dn)
    m['versionNumber'] = ldb.MessageElement(str(newver), ldb.FLAG_MOD_REPLACE, 'versionNumber')
    uext = '[%s%s][%s%s]' % (NULL_GUID, DRIVES_CSE, DRIVES_CSE, GPP_TOOL)
    m['gPCUserExtensionNames'] = ldb.MessageElement(uext, ldb.FLAG_MOD_REPLACE, 'gPCUserExtensionNames')
    mext = '[%s%s]' % (REG_CSE, REG_TOOL)
    m['gPCMachineExtensionNames'] = ldb.MessageElement(mext, ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d drives=%d (machine reprocessing on)' % (newver, len(drives)))

if __name__ == '__main__':
    main()
