#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Filtres WMI : n'appliquer une stratégie QU'AUX POSTES qui remplissent une condition
(édition de Windows, version, portable/fixe, machine virtuelle…).

Une GPO applique sinon les mêmes réglages à TOUS les postes, sans distinction. Le filtre WMI
est le mécanisme natif prévu pour cela : la condition est évaluée PAR LE POSTE lui-même au
moment du traitement des stratégies — donc sans script, sans dépendance au démarrage ni à
l'horloge (contrairement aux scripts de démarrage, qui nous ont déjà joué des tours).

Le filtre est un objet « msWMI-Som » de l'annuaire ; la GPO le référence par son attribut
« gPCWQLFilter ». Les deux sont supportés par Samba AD (vérifié sur cette installation).

Usage : gpo-wmi.py list
        gpo-wmi.py set <{GUID de la GPO}> <clé de filtre>
        gpo-wmi.py clear <{GUID de la GPO}>
"""
import sys, uuid, datetime, subprocess

# ── Filtres proposés ─────────────────────────────────────────────────────────
# On interroge « OperatingSystemSKU » (code NUMÉRIQUE de l'édition) et non « Caption » :
# sur un Windows français, Caption vaut « Microsoft Windows 11 Professionnel » — un filtre
# écrit sur « Professional » ne correspondrait donc à RIEN. Le code, lui, n'est pas traduit.
#   4, 27, 125 = Entreprise (et variantes N/LTSC)   121, 122 = Éducation
#   48, 49     = Professionnel (et N)               101, 98  = Famille (Core, et N)
FILTRES = {
    'entreprise': (
        "Postes en édition Entreprise ou Éducation",
        'SELECT * FROM Win32_OperatingSystem WHERE OperatingSystemSKU = 4 OR '
        'OperatingSystemSKU = 27 OR OperatingSystemSKU = 125 OR OperatingSystemSKU = 121 OR '
        'OperatingSystemSKU = 122'),
    'pro': (
        "Postes en édition Famille ou Professionnel",
        'SELECT * FROM Win32_OperatingSystem WHERE OperatingSystemSKU = 48 OR '
        'OperatingSystemSKU = 49 OR OperatingSystemSKU = 101 OR OperatingSystemSKU = 98'),
    'win11': (
        "Postes sous Windows 11",
        'SELECT * FROM Win32_OperatingSystem WHERE BuildNumber >= "22000"'),
    'win10': (
        "Postes sous Windows 10",
        'SELECT * FROM Win32_OperatingSystem WHERE BuildNumber < "22000" AND Version LIKE "10.%"'),
    'portable': (
        "Ordinateurs portables",
        'SELECT * FROM Win32_ComputerSystem WHERE PCSystemType = 2'),
    'fixe': (
        "Ordinateurs fixes",
        'SELECT * FROM Win32_ComputerSystem WHERE PCSystemType = 1'),
    'vm': (
        "Machines virtuelles",
        'SELECT * FROM Win32_ComputerSystem WHERE Model LIKE "%Virtual%" OR '
        'Manufacturer LIKE "%VMware%" OR Manufacturer LIKE "%innotek%" OR Manufacturer LIKE "%QEMU%"'),
    'physique': (
        "Ordinateurs physiques (hors machines virtuelles)",
        'SELECT * FROM Win32_ComputerSystem WHERE NOT Model LIKE "%Virtual%" AND '
        'NOT Manufacturer LIKE "%VMware%" AND NOT Manufacturer LIKE "%innotek%" AND '
        'NOT Manufacturer LIKE "%QEMU%"'),
}

NS = 'root\\CIMv2'

def db_open():
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    return SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)

def realm():
    return subprocess.run(['testparm', '-s', '--parameter-name=realm'],
                          capture_output=True, text=True).stdout.strip().lower()

def base_dn(rl):
    return ','.join('DC=' + p for p in rl.split('.'))

def stamp():
    # Format attendu par l'annuaire pour msWMI-ChangeDate / CreationDate.
    return datetime.datetime.now().strftime('%Y%m%d%H%M%S.000000-000')

def parm2(query):
    """Encodage de la requête : « <nb>;3;<len ns>;<len requête>;WQL;<ns>;<requête>; ».
    Les longueurs DOIVENT être exactes, sinon le poste ignore le filtre en silence."""
    return '1;3;%d;%d;WQL;%s;%s;' % (len(NS), len(query), NS, query)

def ensure_filter(db, bdn, key):
    """Crée le filtre s'il n'existe pas encore, et renvoie son {GUID}."""
    import ldb
    label, query = FILTRES[key]
    som = 'CN=SOM,CN=WMIPolicy,CN=System,%s' % bdn
    name = 'Bastion — %s' % label
    esc = name.replace('\\', '\\5c').replace('(', '\\28').replace(')', '\\29').replace('*', '\\2a')
    res = db.search(base=som, scope=ldb.SCOPE_ONELEVEL,
                    expression='(msWMI-Name=%s)' % esc, attrs=['msWMI-ID'])
    if res:
        return str(res[0]['msWMI-ID'][0])
    gid = '{%s}' % str(uuid.uuid4()).upper()
    m = ldb.Message()
    m.dn = ldb.Dn(db, 'CN=%s,%s' % (gid, som))
    m['objectClass']        = ldb.MessageElement('msWMI-Som',        ldb.FLAG_MOD_ADD, 'objectClass')
    m['instanceType']       = ldb.MessageElement('4',                ldb.FLAG_MOD_ADD, 'instanceType')
    m['showInAdvancedViewOnly'] = ldb.MessageElement('TRUE',         ldb.FLAG_MOD_ADD, 'showInAdvancedViewOnly')
    m['msWMI-Name']         = ldb.MessageElement(name,               ldb.FLAG_MOD_ADD, 'msWMI-Name')
    m['msWMI-Parm1']        = ldb.MessageElement(label,              ldb.FLAG_MOD_ADD, 'msWMI-Parm1')
    m['msWMI-Parm2']        = ldb.MessageElement(parm2(query),       ldb.FLAG_MOD_ADD, 'msWMI-Parm2')
    m['msWMI-ID']           = ldb.MessageElement(gid,                ldb.FLAG_MOD_ADD, 'msWMI-ID')
    m['msWMI-Author']       = ldb.MessageElement('Administrator@%s' % realm(), ldb.FLAG_MOD_ADD, 'msWMI-Author')
    s = stamp()
    m['msWMI-CreationDate'] = ldb.MessageElement(s, ldb.FLAG_MOD_ADD, 'msWMI-CreationDate')
    m['msWMI-ChangeDate']   = ldb.MessageElement(s, ldb.FLAG_MOD_ADD, 'msWMI-ChangeDate')
    db.add(m)
    return gid

def main():
    import ldb
    action = sys.argv[1] if len(sys.argv) > 1 else ''
    rl = realm(); bdn = base_dn(rl)

    if action == 'list':
        for k, (label, q) in FILTRES.items():
            print('%s\t%s' % (k, label))
        return

    if action == 'status':
        # « {GUID de la GPO} TAB clé de filtre » pour chaque stratégie filtrée.
        import ldb
        db = db_open()
        soms = {}
        try:
            for e in db.search(base='CN=SOM,CN=WMIPolicy,CN=System,%s' % bdn,
                               scope=ldb.SCOPE_ONELEVEL, attrs=['msWMI-ID', 'msWMI-Name']):
                soms[str(e['msWMI-ID'][0])] = str(e['msWMI-Name'][0])
        except Exception:
            pass
        lbl2key = {v[0]: k for k, v in FILTRES.items()}
        for e in db.search(base='CN=Policies,CN=System,%s' % bdn, scope=ldb.SCOPE_ONELEVEL,
                           expression='(objectClass=groupPolicyContainer)', attrs=['cn', 'gPCWQLFilter']):
            if 'gPCWQLFilter' not in e:
                continue
            ref = str(e['gPCWQLFilter'][0])            # « [domaine;{GUID};0] »
            parts = ref.strip('[]').split(';')
            fid = parts[1] if len(parts) > 1 else ''
            nom = soms.get(fid, '')
            key = lbl2key.get(nom.replace('Bastion — ', ''), '')
            if key:
                print('%s\t%s' % (str(e['cn'][0]), key))
        return

    if action not in ('set', 'clear') or len(sys.argv) < 3:
        print('usage: gpo-wmi.py list | set <{GUID}> <filtre> | clear <{GUID}>', file=sys.stderr)
        sys.exit(2)

    guid = sys.argv[2]
    if not (guid.startswith('{') and guid.endswith('}')):
        print('ERROR: GUID de GPO invalide', file=sys.stderr); sys.exit(2)
    db = db_open()
    gpo_dn = 'CN=%s,CN=Policies,CN=System,%s' % (guid, bdn)
    try:
        db.search(base=gpo_dn, scope=ldb.SCOPE_BASE, attrs=['cn'])
    except Exception:
        print('ERROR: stratégie introuvable', file=sys.stderr); sys.exit(1)

    m = ldb.Message(); m.dn = ldb.Dn(db, gpo_dn)
    if action == 'clear':
        m['gPCWQLFilter'] = ldb.MessageElement([], ldb.FLAG_MOD_REPLACE, 'gPCWQLFilter')
        db.modify(m)
        print('filtre retire')
        return

    key = sys.argv[3] if len(sys.argv) > 3 else ''
    if key not in FILTRES:
        print('ERROR: filtre inconnu', file=sys.stderr); sys.exit(2)
    fid = ensure_filter(db, bdn, key)
    # Référence portée par la GPO : « [domaine;{GUID du filtre};0] ».
    m['gPCWQLFilter'] = ldb.MessageElement('[%s;%s;0]' % (rl, fid), ldb.FLAG_MOD_REPLACE, 'gPCWQLFilter')
    db.modify(m)
    print('filtre %s applique (%s)' % (key, fid))

if __name__ == '__main__':
    main()
