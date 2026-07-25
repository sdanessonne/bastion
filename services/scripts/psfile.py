#!/usr/bin/python3
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
"""
Écriture des scripts destinés aux POSTES Windows (PowerShell et cmd).

POURQUOI CE MODULE EXISTE
─────────────────────────
Windows PowerShell 5.1 — celui livré avec Windows 11 — lit un fichier .ps1 SANS
marque d'ordre d'octets comme de l'ANSI (page de codes 1252), pas comme de l'UTF-8.
Un caractère français encodé en UTF-8 s'y décompose alors en plusieurs octets, et
CERTAINS DE CES OCTETS SONT DES GUILLEMETS en CP1252 :

    « — » (tiret cadratin) = E2 80 94  →  lu « â€" »   … 0x94 est un GUILLEMET FERMANT
    « – » (tiret demi-cadratin)        →  lu « â€" »   … 0x93 est un GUILLEMET OUVRANT
    « ─ » (filet de tableau)           →  lu « â"€ »

Dans une CHAÎNE PowerShell, ce guillemet parasite la FERME au milieu : le fichier
entier devient inanalysable et le script n'exécute PAS UNE SEULE LIGNE — pas même
sa propre journalisation. C'est très exactement la panne rencontrée sur
« photo-tile.ps1 » (« Le terminateur " est manquant dans la chaîne ») et celle qui
menaçait le script des applications.

DEUX PROTECTIONS, VOLONTAIREMENT REDONDANTES
────────────────────────────────────────────
1. On écrit une MARQUE D'ORDRE D'OCTETS UTF-8 en tête : PowerShell détecte alors
   l'encodage et lit correctement les accents. C'est la vraie correction.
2. On remplace malgré tout les caractères dont la lecture ANSI produit un
   guillemet. Si un maillon de la chaîne perdait la marque (recopie, édition par
   un outil tiers, extraction d'archive), le script resterait analysable.

Les fichiers .cmd, eux, n'acceptent AUCUNE marque d'ordre d'octets (l'interpréteur
la prendrait pour le début de la première commande) : ils sont écrits en ASCII pur,
accents translittérés.
"""
import os
import unicodedata

# Caractères dont la lecture en CP1252 fabrique un guillemet ou une apostrophe :
# ceux-là sont dangereux jusque DANS les chaînes. Les accents ordinaires (é, à, ç)
# se lisent mal mais ne cassent rien — la marque d'ordre d'octets les rétablit.
DANGEREUX = {
    '—': '-',    # — tiret cadratin      (E2 80 94 → …0x94 = guillemet fermant)
    '–': '-',    # – tiret demi-cadratin (E2 80 93 → …0x93 = guillemet ouvrant)
    '―': '-',    # ― barre horizontale
    '─': '-',    # ─ filet de tableau    (E2 94 80 → 0x94 = guillemet fermant)
    '━': '-', '┄': '-', '═': '=',
    '“': '"', '”': '"', '„': '"',   # guillemets courbes
    '‘': "'", '’': "'", '‚': "'",   # apostrophes courbes
    '…': '...',  # … points de suspension
    '→': '->', '←': '<-', '↔': '<->',
    '•': '*', '·': '*',
    '✓': 'OK', '✔': 'OK', '✗': 'X',
    ' ': ' ',    # espace insecable
}


def assainir(texte):
    """Retire les caractères dont la lecture ANSI produirait un guillemet."""
    for mauvais, bon in DANGEREUX.items():
        texte = texte.replace(mauvais, bon)
    return texte


def en_ascii(texte):
    """Translittère tout en ASCII (pour les .cmd, qui ne tolèrent pas de marque)."""
    texte = assainir(texte)
    texte = texte.replace('«', '"').replace('»', '"').replace('°', 'deg')
    texte = unicodedata.normalize('NFKD', texte)
    return texte.encode('ascii', 'ignore').decode('ascii')


def ecrire_ps1(chemin, texte, crlf=True):
    """Écrit un script PowerShell : marque d'ordre d'octets UTF-8 + caractères sûrs."""
    texte = assainir(texte)
    if crlf:
        texte = texte.replace('\r\n', '\n').replace('\n', '\r\n')
    with open(chemin, 'wb') as w:
        w.write(b'\xef\xbb\xbf' + texte.encode('utf-8'))
    os.chmod(chemin, 0o644)
    return chemin


def ecrire_cmd(chemin, texte, crlf=True):
    """Écrit un script cmd : ASCII pur, SANS marque d'ordre d'octets."""
    texte = en_ascii(texte)
    if crlf:
        texte = texte.replace('\r\n', '\n').replace('\n', '\r\n')
    with open(chemin, 'wb') as w:
        w.write(texte.encode('ascii'))
    os.chmod(chemin, 0o644)
    return chemin
