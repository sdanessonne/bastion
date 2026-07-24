#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Diagnostic de santé des stratégies de groupe (GPO) « Bastion — … ».

Pour CHAQUE GPO Bastion, contrôle (lecture seule) :
  • Lien au domaine          — la GPO est-elle liée quelque part (sinon elle ne s'applique pas) ?
  • Cohérence de version      — GPT.INI (SYSVOL) == versionNumber (AD) ? (sinon le poste peut ne pas rejouer)
  • ACL SYSVOL lisible        — « Utilisateurs authentifiés » ont-ils la LECTURE ? (sinon le poste ne lit pas la GPO)
  • CSE ↔ fichiers            — chaque extension déclarée a-t-elle son fichier (Registry.pol / Drives.xml / Scripts) ?
  • Fichiers orphelins        — un Registry.pol/Drives.xml sans CSE déclaré (réglages qui ne s'appliqueront pas).

Sortie : JSON sur stdout — [{name,guid,worst,checks:[{label,status,detail}]}], trié pire d'abord.
Statuts : ok | warn | fail.  Usage : gpo-health.py
"""
import sys, os, json, subprocess, re

REG_CSE    = '{35378EAC-683F-11D2-A89A-00C04FBBCFA2}'   # Registre
DRIVES_CSE = '{5794DAFD-BE60-433F-88A2-1A31939AC01F}'   # Drive Maps (GPP)
SCRIPTS_CSE= '{42B5FAAE-6536-11D2-AE5A-0000F87571E3}'   # Scripts (ouverture/démarrage)

def sh(*a):
    return subprocess.run(a, capture_output=True, text=True)

def gpt_version(sysvol):
    """Version lue dans GPT.INI (SYSVOL)."""
    try:
        for line in open(os.path.join(sysvol, 'GPT.INI'), encoding='latin-1'):
            m = re.match(r'\s*Version\s*=\s*(\d+)', line, re.I)
            if m:
                return int(m.group(1))
    except Exception:
        pass
    return None

def acl_reads_ok(path):
    """« Utilisateurs authentifiés » (AU / S-1-5-11) ont-ils un accès en lecture sur la GPO ?"""
    r = sh('samba-tool', 'ntacl', 'get', '--as-sddl', path)
    sddl = r.stdout or ''
    # Un ACE d'autorisation pour AU avec droits de lecture (0x1200a9) ou contrôle total (FA).
    return bool(re.search(r'\(A;[^)]*;(0x1200a9|FA|GR|GA)[^)]*;;;(AU|S-1-5-11)\)', sddl))

def main():
    realm = sh('testparm', '-s', '--parameter-name=realm').stdout.strip().lower()
    if not realm:
        print('[]'); return
    base = ','.join('DC=' + p for p in realm.split('.'))
    sysvol_root = '/var/lib/samba/sysvol/%s/Policies' % realm

    import ldb
    from samba.samdb import SamDB
    from samba.auth import system_session
    from samba.param import LoadParm
    lp = LoadParm(); lp.load_default()
    db = SamDB(url='/var/lib/samba/private/sam.ldb', session_info=system_session(), lp=lp)

    # Ensemble des GUID liés QUELQUE PART (racine + OU) — un gPLink liste les GPO d'un conteneur.
    linked = set()
    for e in db.search(base=base, scope=ldb.SCOPE_SUBTREE,
                       expression='(gPLink=*)', attrs=['gPLink']):
        for g in re.findall(r'\{[0-9A-Fa-f-]+\}', e['gPLink'][0].decode()):
            linked.add(g.upper())

    # Toutes les GPO du domaine.
    pol_dn = 'CN=Policies,CN=System,%s' % base
    gpos = db.search(base=pol_dn, scope=ldb.SCOPE_ONELEVEL,
                     expression='(objectClass=groupPolicyContainer)',
                     attrs=['cn', 'displayName', 'versionNumber',
                            'gPCUserExtensionNames', 'gPCMachineExtensionNames'])

    rank = {'ok': 0, 'warn': 1, 'fail': 2}
    report = []
    for g in gpos:
        name = g['displayName'][0].decode() if 'displayName' in g else ''
        if not name.startswith('Bastion'):
            continue
        guid = g['cn'][0].decode().upper()
        uext = g['gPCUserExtensionNames'][0].decode() if 'gPCUserExtensionNames' in g else ''
        mext = g['gPCMachineExtensionNames'][0].decode() if 'gPCMachineExtensionNames' in g else ''
        adver = int(str(g['versionNumber'][0])) if 'versionNumber' in g else 0
        sysvol = os.path.join(sysvol_root, guid)
        checks = []

        # 1) Lien au domaine
        if guid in linked:
            checks.append(('Lien au domaine', 'ok', 'Liée et active.'))
        else:
            checks.append(('Lien au domaine', 'fail', 'Non liée — la stratégie ne s\'applique à aucun poste.'))

        # 2) SYSVOL présent
        if not os.path.isdir(sysvol):
            checks.append(('Dossier SYSVOL', 'fail', 'Dossier de stratégie introuvable sur le disque.'))
            worst = 'fail'
            report.append({'name': name, 'guid': guid, 'worst': worst,
                           'checks': [{'label': l, 'status': s, 'detail': d} for l, s, d in checks]})
            continue

        # 3) Cohérence de version GPT.INI ↔ AD
        gv = gpt_version(sysvol)
        if gv is None:
            checks.append(('Version (GPT.INI)', 'warn', 'GPT.INI illisible ou sans version.'))
        elif gv == adver:
            checks.append(('Cohérence de version', 'ok', 'GPT.INI et annuaire concordent (%d).' % adver))
        else:
            checks.append(('Cohérence de version', 'warn',
                           'GPT.INI=%d mais annuaire=%d — le poste peut ne pas rejouer la stratégie.' % (gv, adver)))

        # 4) ACL SYSVOL lisible par les postes
        if acl_reads_ok(sysvol):
            checks.append(('Permissions SYSVOL', 'ok', 'Lisible par les postes (Utilisateurs authentifiés).'))
        else:
            checks.append(('Permissions SYSVOL', 'fail',
                           'Les postes ne peuvent pas LIRE la stratégie — cliquez « Réparer les permissions ».'))

        # 5) CSE déclaré ↔ fichier présent
        def has(cse, ext): return cse.upper() in ext.upper()
        pairs = [
            (has(REG_CSE, mext),    os.path.join(sysvol, 'Machine', 'Registry.pol'), 'Registre (ordinateur)'),
            (has(REG_CSE, uext),    os.path.join(sysvol, 'User', 'Registry.pol'),    'Registre (utilisateur)'),
            (has(DRIVES_CSE, uext), os.path.join(sysvol, 'User', 'Preferences', 'Drives', 'Drives.xml'), 'Lecteurs réseau'),
        ]
        miss = [lbl for present, f, lbl in pairs if present and not os.path.exists(f)]
        if has(SCRIPTS_CSE, mext) and not (os.path.exists(os.path.join(sysvol, 'Machine', 'Scripts', 'scripts.ini'))
                                           or os.path.exists(os.path.join(sysvol, 'Machine', 'Scripts', 'psscripts.ini'))):
            miss.append('Scripts (ordinateur)')
        if miss:
            checks.append(('Fichiers de stratégie', 'fail', 'Extension déclarée mais fichier absent : ' + ', '.join(miss) + '.'))
        else:
            checks.append(('Fichiers de stratégie', 'ok', 'Tous les fichiers déclarés sont présents.'))

        # 6) Fichiers orphelins (présents sans CSE déclaré)
        orphans = []
        if os.path.exists(os.path.join(sysvol, 'Machine', 'Registry.pol')) and not has(REG_CSE, mext):
            orphans.append('Registre (ordinateur)')
        if os.path.exists(os.path.join(sysvol, 'User', 'Registry.pol')) and not has(REG_CSE, uext):
            orphans.append('Registre (utilisateur)')
        if os.path.exists(os.path.join(sysvol, 'User', 'Preferences', 'Drives', 'Drives.xml')) and not has(DRIVES_CSE, uext):
            orphans.append('Lecteurs réseau')
        if orphans:
            checks.append(('Extensions', 'warn', 'Fichier présent mais extension non déclarée : ' + ', '.join(orphans) + ' — réglages ignorés.'))

        worst = 'ok'
        for _, s, _ in checks:
            if rank[s] > rank[worst]:
                worst = s
        report.append({'name': name, 'guid': guid, 'worst': worst,
                       'checks': [{'label': l, 'status': s, 'detail': d} for l, s, d in checks]})

    report.sort(key=lambda r: -rank[r['worst']])
    print(json.dumps(report, ensure_ascii=False))

if __name__ == '__main__':
    main()
