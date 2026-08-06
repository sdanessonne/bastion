# -*- coding: utf-8 -*-
"""Bastion - verification du HTML des sections d aide.

Verifie que chaque section d'aide contient du HTML equilibre.

Une balise non fermee dans une section ne casse pas PHP : elle avale
silencieusement les sections suivantes a l'affichage. C'est exactement le genre
de panne qu'on ne voit qu'en regardant la page, longtemps apres.
"""
import io, re
from html.parser import HTMLParser

import os
CIBLE = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'admin', 'aide.php')
AUTOFERMANTES = {'br', 'hr', 'img', 'input', 'meta', 'link'}


class Verif(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.pile = []
        self.erreurs = []

    def handle_starttag(self, tag, attrs):
        if tag not in AUTOFERMANTES:
            self.pile.append(tag)

    def handle_endtag(self, tag):
        if tag in AUTOFERMANTES:
            return
        if not self.pile:
            self.erreurs.append("</%s> sans ouverture" % tag)
        elif self.pile[-1] != tag:
            self.erreurs.append("</%s> alors que <%s> est ouvert" % (tag, self.pile[-1]))
            if tag in self.pile:
                while self.pile and self.pile.pop() != tag:
                    pass
        else:
            self.pile.pop()


s = io.open(CIBLE, encoding='utf-8').read()
AP = chr(39)
BS = chr(92)
motif = r"\[" + AP + r"([a-z0-9-]+)" + AP + r", " + AP + r"([^" + AP + r"]*)" + AP + \
        r", " + AP + r"(.*?)" + AP + r", " + AP + r"(.*?)" + AP + r"\],\n"

total = mauvaises = 0
for m in re.finditer(motif, s, re.S):
    anc, ico, titre, corps = m.groups()
    # Deferrer les echappements PHP pour retrouver le HTML reel.
    corps = corps.replace(BS + AP, AP).replace(BS + BS, BS)
    v = Verif()
    v.feed(corps)
    v.close()
    total += 1
    if v.erreurs or v.pile:
        mauvaises += 1
        print("  DESEQUILIBRE  %-22s %s" % (anc, "; ".join(v.erreurs + ["<%s> jamais fermee" % t for t in v.pile])))

print("\n%d sections verifiees, %d avec du HTML desequilibre." % (total, mauvaises))
raise SystemExit(1 if mauvaises else 0)
