#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Contrôle les scripts destinés aux POSTES Windows (.ps1 et .cmd) : encodage et
caractères. Refuse ce qui rendrait un script inanalysable une fois sur le poste.

Trois règles, chacune correspondant à une panne réellement survenue :

  1. Un .ps1 doit porter la marque d'ordre d'octets UTF-8, ou n'être que de l'ASCII.
     Sans elle, PowerShell 5.1 lit le fichier en CP1252 (panne « photo-tile.ps1 » :
     « Le terminateur " est manquant dans la chaîne »).

  2. Aucun script ne doit contenir de caractère dont la lecture en CP1252 produit un
     GUILLEMET : « — », « – », « ─ ». Filet de sécurité si la marque venait à sauter.

  3. Un .cmd doit être en ASCII pur et SANS marque (l'interpréteur de commandes la
     prendrait pour le début de sa première commande).

Deux terrains :
  * le DÉPÔT       — les scripts livrés tels quels (services/tftp, services/tools) ;
  * le SYSVOL      — ce qui est RÉELLEMENT déployé, seul terrain qui compte vraiment.

Usage : check-scripts.py [racine-du-dépôt]     (code de sortie 1 si un défaut est trouvé)
"""
import os
import re
import sys

BOM_UTF8 = b'\xef\xbb\xbf'

# EXCEPTION ASSUMÉE — le menu d'amorçage PXE.
# « startnet.cmd » dessine son menu avec des caractères semi-graphiques ; il est stocké en
# UTF-8 dans le dépôt et CONVERTI EN CP437 au moment de l'injection dans le WIM
# (services/scripts/setup-pxe.sh, « iconv -f UTF-8 -t CP437 »). La règle « ASCII pur » ne
# s'y applique donc pas : la bonne règle est que tout y soit REPRÉSENTABLE en CP437, sinon
# iconv perdrait des caractères et le menu s'afficherait troué.
CONVERTIS = {'services/tftp/startnet.cmd': 'cp437',
             'services/tftp/winpeshl.ini': 'cp437'}
# Caractères dont l'octet de fin, lu en CP1252, est un guillemet : ils cassent
# une chaîne PowerShell au milieu. Voir psfile.DANGEREUX.
TUEURS = {'—': 'tiret cadratin', '–': 'tiret demi-cadratin',
          '―': 'barre horizontale', '─': 'filet de tableau',
          '“': 'guillemet courbe', '”': 'guillemet courbe'}

defauts = []


def controler(chemin, etiquette):
    try:
        brut = open(chemin, 'rb').read()
    except OSError:
        return
    ext = os.path.splitext(chemin)[1].lower()
    ascii_pur = all(o < 128 for o in brut)

    # Fichier converti à la livraison : on contrôle la représentabilité, pas l'ASCII.
    cible = CONVERTIS.get(etiquette)
    if cible:
        try:
            brut.decode('utf-8').encode(cible)
        except UnicodeError as e:
            defauts.append('%s : caractere non representable en %s -> perdu par iconv (%s)'
                           % (etiquette, cible.upper(), e))
        return

    if ext == '.ps1':
        if not brut.startswith(BOM_UTF8) and not ascii_pur:
            defauts.append('%s : .ps1 accentue SANS marque UTF-8 -> lu en CP1252 par '
                           'PowerShell 5.1' % etiquette)
    elif ext == '.cmd':
        if brut.startswith(BOM_UTF8):
            defauts.append('%s : .cmd AVEC marque UTF-8 -> premiere commande illisible' % etiquette)
        if not ascii_pur:
            defauts.append('%s : .cmd non-ASCII' % etiquette)

    texte = brut.decode('utf-8', 'replace')
    for n, ligne in enumerate(texte.split('\n'), 1):
        for c, nom in TUEURS.items():
            if c in ligne:
                # On ne REPRODUIT pas le caractere fautif dans le message : ce controleur
                # doit pouvoir s'afficher sur une console CP1252 sans planter lui-meme.
                defauts.append('%s:%d : %s (U+%04X) -> devient un guillemet en CP1252'
                               % (etiquette, n, nom, ord(c)))
                break


def parcourir(racine, etiquette_base):
    for base, dirs, fichiers in os.walk(racine):
        dirs[:] = [d for d in dirs if d not in ('.git', 'node_modules')]
        for f in fichiers:
            if f.lower().endswith(('.ps1', '.cmd')):
                p = os.path.join(base, f)
                controler(p, etiquette_base + os.path.relpath(p, racine).replace('\\', '/'))


def main():
    racine = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

    # Un chemin de dépôt faux ne doit PAS passer au vert : sans ce garde-fou, le contrôle
    # ne trouverait rien à inspecter et annoncerait « conforme » en n'ayant rien lu.
    if not os.path.isdir(os.path.join(racine, 'services')):
        print('ERREUR : depot introuvable (%s) - rien n\'a ete controle.' % racine)
        return 2

    for sous in ('services/tftp', 'services/tools', 'provisioning'):
        d = os.path.join(racine, sous)
        if os.path.isdir(d):
            parcourir(d, sous + '/')

    # Le terrain qui compte : ce que les postes lisent vraiment.
    sysvol = '/var/lib/samba/sysvol'
    if os.path.isdir(sysvol):
        parcourir(sysvol, 'SYSVOL/')

    if defauts:
        print('%d defaut(s) :' % len(defauts))
        for d in defauts:
            print('  X ' + d)
        return 1
    print('Scripts postes : encodage et caracteres conformes.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
