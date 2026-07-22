#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Change le mot de passe d'un compte SYSTÈME, appelé par la console d'administration via
# sudo. Le nouveau mot de passe arrive sur l'ENTRÉE STANDARD, jamais en argument.
#
# ── CE SCRIPT DONNE À LA CONSOLE WEB UN POUVOIR IMPORTANT ────────────────────
# Pouvoir changer un mot de passe système depuis le web, c'est pratique — et c'est aussi
# une cible : quiconque prendrait la main sur la console pourrait se donner un accès à la
# machine. Trois barrières le limitent :
#   1. la console EXIGE de ressaisir le mot de passe administrateur avant d'appeler ce
#      script (côté PHP) — une session volée ne suffit pas ;
#   2. la règle sudo n'autorise QUE deux invocations précises (voir plus bas), pas un
#      « proxyfibre-syspasswd n'importe-quel-compte » ;
#   3. ce script n'accepte lui-même que deux comptes, en liste fermée.
# Chaque barrière est indépendante : il en faut UNE seule qui tienne pour que l'attaque
# échoue.
#
# Usage : printf '%s' "<nouveau mdp>" | proxyfibre-syspasswd <proxyfibre|root>
set -eu

compte="${1:-}"
# Liste FERMÉE. « root » est accepté parce qu'il a été demandé, mais l'administrateur du
# système se connecte normalement avec le compte « proxyfibre » (puis sudo) : changer SON
# mot de passe suffit à reprendre la main sans rouvrir la connexion directe en root.
case "$compte" in
    proxyfibre|root) : ;;
    *) echo "ERREUR: compte système non autorisé."; exit 2 ;;
esac

# Mot de passe sur stdin, une seule ligne. « IFS= read -r » ne coupe rien et ne déballe
# aucun échappement : le mot de passe arrive tel quel. « || true » : sans saut de ligne
# final, read rend un code non nul mais a bien lu la valeur — set -e ne doit pas sortir.
IFS= read -r mdp || true

# On refuse ce qui casserait chpasswd ou trahirait une manipulation :
if [ "${#mdp}" -lt 8 ]; then
    echo "ERREUR: mot de passe trop court (8 caractères minimum)."; exit 2
fi
case "$mdp" in
    *[[:cntrl:]]*) echo "ERREUR: le mot de passe contient un caractère de contrôle."; exit 2 ;;
esac

# chpasswd lit « compte:motdepasse » et coupe au PREMIER deux-points : un « : » dans le mot
# de passe ne pose donc pas de problème. Le saut de ligne, lui, est déjà exclu par read.
if printf '%s:%s\n' "$compte" "$mdp" | chpasswd; then
    # chpasswd déverrouille au passage un compte qui était verrouillé (cas de root) : on
    # le dit, pour que l'administrateur sache que la connexion directe redevient possible.
    echo "OK: mot de passe de « $compte » changé."
else
    echo "ERREUR: chpasswd a échoué."; exit 1
fi
