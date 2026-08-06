#!/bin/sh
# Bastion — © 2026 Mickaël MONESTIER (Mle 110.480). Tous droits réservés. Voir LICENCE.txt.
#
# Activation / désactivation des fonctions OPTIONNELLES de la passerelle.
#
# « Optionnelle » veut dire : la passerelle continue d'authentifier, de filtrer et de
# journaliser sans elle. Le portail captif, le DNS/DHCP, la base et le contrôleur de
# domaine ne figurent donc PAS ici — les couper n'est pas un réglage, c'est une panne.
# Pour ceux-là, la page Services offre déjà un redémarrage ponctuel.
#
# L'état est TOUJOURS relu sur systemd, jamais dans une table. Un indicateur qui dit
# « activé » pendant que le service est mort est pire que pas d'indicateur : il fait
# chercher la panne ailleurs.
#
# Usage : fonctions-ctl.sh state | enable <fonction> | disable <fonction>
set -eu

# fonction : unités systemd qui la composent (plusieurs pour certaines).
unites() {
  case "$1" in
    antivirus) echo "clamav-daemon clamav-freshclam" ;;
    distance)  echo "bastion-hbbs bastion-hbbr" ;;
    kms)       echo "proxyfibre-kms" ;;
    wifi)      echo "hostapd" ;;
    historique) echo "proxyfibre-weblog" ;;
    *)         echo "" ;;
  esac
}

# Unites SOCKET associees. systemd peut RELANCER un service a la demande par son
# socket, meme service desactive : sans les couper aussi, l'etat « arretee » serait
# faux et l'antivirus repartirait au premier acces.
sockets() {
  case "$1" in
    antivirus) echo "clamav-daemon.socket" ;;
    *)         echo "" ;;
  esac
}

FONCTIONS="antivirus distance kms wifi historique"

err() { printf 'ERREUR: %s\n' "$*" >&2; exit 1; }

# Mémoire réellement consommée par une fonction, en mégaoctets. Sert à répondre à la
# seule question qui compte quand on hésite à couper quelque chose : « ça me rend quoi ? »
memoire() {
  t=0
  for u in $(unites "$1"); do
    m=$(systemctl show "$u.service" -p MemoryCurrent --value 2>/dev/null || echo 0)
    case "$m" in ''|'[not set]'|18446744073709551615) m=0 ;; esac
    t=$((t + m))
  done
  echo $((t / 1048576))
}

etat_fonction() {
  f="$1"; us=$(unites "$f")
  [ -n "$us" ] || return 1
  installe=1; actifs=0; total=0; auto=0
  for u in $us; do
    total=$((total + 1))
    [ -n "$(systemctl list-unit-files "$u.service" --no-legend 2>/dev/null)" ] || installe=0
    [ "$(systemctl is-active "$u" 2>/dev/null || true)" = "active" ] && actifs=$((actifs + 1))
    [ "$(systemctl is-enabled "$u" 2>/dev/null || true)" = "enabled" ] && auto=$((auto + 1))
  done
  # « partiel » est un état à part entière, pas un arrondi : une fonction dont une seule
  # unité tourne a toutes les apparences du bon fonctionnement et ne marche pas.
  if [ "$installe" -eq 0 ]; then rendu=absente
  elif [ "$actifs" -eq 0 ]; then rendu=arretee
  elif [ "$actifs" -lt "$total" ]; then rendu=partielle
  else rendu=active
  fi
  printf '{"nom":"%s","etat":"%s","unites":%d,"actives":%d,"auto":%d,"memoire_mo":%d}' \
         "$f" "$rendu" "$total" "$actifs" "$auto" "$(memoire "$f")"
}

case "${1:-state}" in
  state)
    printf '['
    sep=""
    for f in $FONCTIONS; do printf '%s' "$sep"; etat_fonction "$f"; sep=","; done
    printf ']\n'
    ;;

  enable|disable)
    f="${2:-}"
    us=$(unites "$f")
    [ -n "$us" ] || err "fonction inconnue : « $f »"
    for u in $us; do
      [ -n "$(systemctl list-unit-files "$u.service" --no-legend 2>/dev/null)" ] \
        || err "l'unité $u n'est pas installée sur cette passerelle"
    done
    # L'historique de navigation répond à une OBLIGATION LÉGALE de conservation : c'est
    # une des raisons d'être de cette passerelle. Le couper depuis une page web, d'un
    # clic et sans trace, serait trop facile. Il reste visible et surveillable, mais on
    # refuse ici — le désactiver suppose une décision assumée, en ligne de commande.
    if [ "$1" = "disable" ] && [ "$f" = "historique" ]; then
      err "l'historique de navigation répond à une obligation légale de conservation : refusé"
    fi
    if [ "$1" = "enable" ]; then
      # « --now » : on démarre ET on rend permanent. Faire l'un sans l'autre donne une
      # fonction qui marche jusqu'au prochain redémarrage, ou l'inverse — deux façons
      # de croire que c'est fait alors que ça ne l'est qu'à moitié.
      systemctl enable --now $us $(sockets "$f") >&2 || err "activation impossible ($us)"
    else
      # Le bavardage de systemd part sur STDERR : la sortie standard ne doit porter
      # que le JSON, sinon l'appelant ne peut pas le decoder.
      systemctl disable --now $us $(sockets "$f") >&2 || err "désactivation impossible ($us)"
    fi
    # On rend l'état RÉEL après l'action, pas un « ok » de principe.
    etat_fonction "$f"; printf '\n'
    ;;

  *) err "usage: $0 state | enable <fonction> | disable <fonction>" ;;
esac
