#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Dépose le fichier d'associations par défaut d'une GPO et donne son chemin UNC.

── CE QUE WINDOWS PERMET, ET CE QU'IL NE PERMET PAS ──────────────────────────
Depuis Windows 10, le choix du navigateur par défaut d'un utilisateur est scellé
par une empreinte (« UserChoice ») que Microsoft protège volontairement : aucune
stratégie, aucun script ne peut la réécrire. Promettre « Firefox par défaut sur
tous les postes » serait donc un mensonge.

Le SEUL levier pris en charge est le fichier d'associations par défaut, désigné
par la stratégie « DefaultAssociationsConfiguration ». Windows l'applique à la
CRÉATION d'un profil utilisateur — donc à la première ouverture de session d'un
agent sur un poste. Un profil déjà créé garde son choix.

C'est une limite de Windows, pas de Bastion. La page de la console le dit, et
l'inventaire remonte le navigateur réellement en place sur chaque poste : sans
cela, on croirait le parc basculé alors que seuls les nouveaux profils le sont.

── LE CHEMIN UNC N'EST PAS ANODIN ────────────────────────────────────────────
Il est construit sur « dc.<realm> », le nom du CONTRÔLEUR, et jamais sur le nom
de domaine seul : ce projet a déjà constaté qu'un accès par nom de domaine
répond « Élément introuvable » côté poste. Il n'est pas construit non plus sur
« hostname -f », qui renvoie ici un résidu en .local sans rapport avec le realm.

Usage : gpo-defaultapps.py <{GUID}> [suffixe-ProgId]
        Le suffixe par défaut (308046B0AF4A39CB) correspond à une installation
        de Firefox dans son emplacement standard.
"""
import sys, os, subprocess

DEFAUT_SUFFIXE = '308046B0AF4A39CB'

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

def xml(suffixe):
    """Associations confiées à Firefox. Le navigateur, rien d'autre : les images et
    les documents gardent l'application que l'agent connaît."""
    paires = [
        ('.htm',   'FirefoxHTML'), ('.html',  'FirefoxHTML'),
        ('.shtml', 'FirefoxHTML'), ('.xht',   'FirefoxHTML'), ('.xhtml', 'FirefoxHTML'),
        ('http',   'FirefoxURL'),  ('https',  'FirefoxURL'),
    ]
    L = ['<?xml version="1.0" encoding="UTF-8"?>', '<DefaultAssociations>']
    for ident, prog in paires:
        L.append('  <Association Identifier="%s" ProgId="%s-%s" ApplicationName="Firefox" />'
                 % (ident, prog, suffixe))
    L.append('</DefaultAssociations>')
    return '\r\n'.join(L) + '\r\n'

def main():
    guid = sys.argv[1]
    suffixe = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] else DEFAUT_SUFFIXE
    # Le suffixe finit dans un XML lu par Windows : on n'y laisse passer que ce qu'il
    # peut être — des caractères d'empreinte.
    if not all(c in '0123456789ABCDEFabcdef' for c in suffixe) or not 8 <= len(suffixe) <= 32:
        print('ERROR: suffixe ProgId invalide (empreinte hexadécimale attendue)', file=sys.stderr)
        sys.exit(2)

    realm = subprocess.run(['testparm', '-s', '--parameter-name=realm'],
                           capture_output=True, text=True).stdout.strip().lower()
    if not realm:
        print('ERROR: domaine introuvable', file=sys.stderr); sys.exit(1)
    court = subprocess.run(['hostname', '-s'], capture_output=True, text=True).stdout.strip().lower() or 'dc'

    sysvol = '/var/lib/samba/sysvol/%s/Policies/%s' % (realm, guid)
    if not os.path.isdir(sysvol):
        print('ERROR: SYSVOL introuvable (%s)' % sysvol, file=sys.stderr); sys.exit(1)
    ref = os.path.join(sysvol, 'GPT.INI')

    machine = os.path.join(sysvol, 'Machine')
    os.makedirs(machine, exist_ok=True)
    if os.path.exists(ref): copy_ntacl(ref, machine)

    dest = os.path.join(machine, 'DefaultAssociations.xml')
    with open(dest, 'wb') as w:
        w.write(xml(suffixe).encode('utf-8'))
    os.chmod(dest, 0o644)
    if os.path.exists(ref): copy_ntacl(ref, dest)

    # Chemin tel que le poste doit le lire. Voir l'en-tête : nom du contrôleur.
    print('\\\\%s.%s\\SYSVOL\\%s\\Policies\\%s\\Machine\\DefaultAssociations.xml'
          % (court, realm, realm, guid))

if __name__ == '__main__':
    main()
