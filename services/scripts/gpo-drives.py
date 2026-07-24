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
# CSE « Registre » (paramètres MACHINE) — pour retirer l'ancien tatouage GPP.
REG_CSE    = '{35378EAC-683F-11D2-A89A-00C04FBBCFA2}'
REG_TOOL   = '{D02B1F72-3407-48AE-BA88-E8213C6761F1}'
# CSE « Scripts » (script d'ouverture de session UTILISATEUR = net use, méthode fiable).
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

def _u16(s):
    return s.encode('utf-16-le')

def _reg_entry(key, val, typ, data):
    # Une entrée PReg : [key\0;value\0;type;taille;données]
    return (_u16('[') + _u16(key) + b'\x00\x00' + _u16(';') + _u16(val) + b'\x00\x00' +
            _u16(';') + struct.pack('<I', typ) + _u16(';') + struct.pack('<I', len(data)) +
            _u16(';') + data + _u16(']'))

def machine_cleanup_pol():
    """Registry.pol MACHINE volontairement VIDE (en-tête PReg seul).
    Les versions précédentes posaient NoGPOListChanges=0 / NoBackgroundPolicy=0 pour forcer
    le CSE « Drive Maps » à re-jouer les préférences à chaque rafraîchissement. Or ce
    retraitement de fond (~90 min) reconnecte un lecteur DÉJÀ monté → erreur 85
    « Nom de périphérique local déjà utilisé », l'échec récurrent observé. On conserve le
    volet MACHINE (CSE Registre) uniquement pour que le client RETIRE ces valeurs tatouées.
    La fiabilité du 1er montage est assurée par la GPO « Attendre le réseau à l'ouverture de
    session » (SyncForegroundPolicy=1), pas par un retraitement en boucle."""
    return b'PReg' + struct.pack('<I', 1)

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
        # persistent="0" : NE PAS mémoriser la connexion. Une connexion persistante est
        # restaurée par Winlogon AVANT le passage du CSE ; celui-ci retrouve alors la lettre
        # déjà prise → erreur 85 « Nom de périphérique local déjà utilisé ». En non-persistant,
        # GPP remonte le lecteur à chaque session sur une lettre libre.
        props = ('<Properties action="R" thisDrive="NOCHANGE" allDrives="NOCHANGE" userName="" '
                 'path=%s label=%s persistent="0" useLetter="1" letter=%s/>' %
                 (quoteattr(path), quoteattr(label), quoteattr(letter)))
        body.append('<Drive clsid="{935D1B74-9CB8-4e3c-9914-7DD559B7A417}" name=%s status=%s image="2" '
                    'changed=%s uid=%s bypassErrors="1" removePolicy="0">%s</Drive>' %
                    (quoteattr(letter + ':'), quoteattr(letter + ':'), quoteattr(when), quoteattr(uid), props))
    body.append('</Drives>')
    return '\r\n'.join(body) + '\r\n'

def logon_cmd(drives):
    """Script d'ouverture de session (net use) : monte les lecteurs DANS la session interactive
    de l'agent, exactement comme une commande tapée à la main (qui, elle, fonctionne à tous les
    coups). On n'utilise donc PLUS le CSE GPP Drive Maps (qui échouait « Fonction incorrecte »
    en tâche de fond sur ces postes VirtualBox). Le /delete préalable purge un montage résiduel."""
    L = ['@echo off',
         'rem Bastion - connexion des lecteurs reseau (net use, session interactive).']
    for d in drives:
        letter = (d.get('letter', 'Z') or 'Z')[0].upper()
        path = d.get('path', '')
        L.append('net use %s: /delete /y >nul 2>&1' % letter)
        L.append('net use %s: "%s" /persistent:no >nul 2>&1' % (letter, path))
    return '\r\n'.join(L) + '\r\n'

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

    # Volet UTILISATEUR n°1 : Drives.xml VIDE (aucun item GPP). Le CSE GPP Drive Maps échouait
    # « Fonction incorrecte » (0x1) en tâche de fond sur ces VM ; on le neutralise et on monte
    # les lecteurs par un SCRIPT d'ouverture de session (ci-dessous), fiable.
    d = os.path.join(sysvol, 'User', 'Preferences', 'Drives')
    os.makedirs(d, exist_ok=True)
    for p in (os.path.join(sysvol, 'User'), os.path.join(sysvol, 'User', 'Preferences'), d):
        if os.path.exists(ref): copy_ntacl(ref, p)
    xml = os.path.join(d, 'Drives.xml')
    with open(xml, 'wb') as w:
        w.write(drives_xml([], when).encode('utf-8'))
    os.chmod(xml, 0o644)
    if os.path.exists(ref): copy_ntacl(ref, xml)

    # Volet UTILISATEUR n°2 : script d'ouverture de session (net use) — la méthode fiable.
    logon = os.path.join(sysvol, 'User', 'Scripts', 'Logon')
    os.makedirs(logon, exist_ok=True)
    for p in (os.path.join(sysvol, 'User', 'Scripts'), logon):
        if os.path.exists(ref): copy_ntacl(ref, p)
    lcmd = os.path.join(logon, 'bastion-lecteurs.cmd')
    with open(lcmd, 'wb') as w:
        w.write(logon_cmd(drives).encode('utf-8'))
    sini = os.path.join(sysvol, 'User', 'Scripts', 'scripts.ini')
    with open(sini, 'wb') as w:
        w.write(b'\xff\xfe' + "\r\n[Logon]\r\n0CmdLine=bastion-lecteurs.cmd\r\n0Parameters=\r\n".encode('utf-16-le'))
    for p in (lcmd, sini):
        os.chmod(p, 0o644)
        if os.path.exists(ref): copy_ntacl(ref, p)

    # Côté MACHINE : Registry.pol VIDE, seulement pour retirer l'ancien tatouage
    # NoGPOListChanges/NoBackgroundPolicy (voir machine_cleanup_pol).
    md = os.path.join(sysvol, 'Machine')
    os.makedirs(md, exist_ok=True)
    if os.path.exists(ref): copy_ntacl(ref, md)
    mpol = os.path.join(md, 'Registry.pol')
    with open(mpol, 'wb') as w:
        w.write(machine_cleanup_pol())
    os.chmod(mpol, 0o644)
    if os.path.exists(ref): copy_ntacl(ref, mpol)

    # Version : incrémenter le mot HAUT (utilisateur = Drive Maps) ET le mot BAS
    # (ordinateur = Registre : nettoyage du tatouage), sinon le poste ne relit pas la moitié machine.
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
    # CSE utilisateur : GPP Drive Maps (traite le Drives.xml vide) + Scripts (script de session).
    uext = '[%s%s][%s%s][%s%s]' % (NULL_GUID, DRIVES_CSE, SCRIPTS_CSE, SCRIPTS_TOOL, DRIVES_CSE, GPP_TOOL)
    m['gPCUserExtensionNames'] = ldb.MessageElement(uext, ldb.FLAG_MOD_REPLACE, 'gPCUserExtensionNames')
    mext = '[%s%s]' % (REG_CSE, REG_TOOL)
    m['gPCMachineExtensionNames'] = ldb.MessageElement(mext, ldb.FLAG_MOD_REPLACE, 'gPCMachineExtensionNames')
    samdb.modify(m)
    print('OK version=%d drives=%d (script de session net use)' % (newver, len(drives)))

if __name__ == '__main__':
    main()
